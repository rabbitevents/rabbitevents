<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\Message;

use Illuminate\Contracts\Events\Dispatcher as EventsDispatcher;
use RabbitEvents\Listener\Dispatcher;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Facade;
use RabbitEvents\Foundation\Message;
use RabbitEvents\Listener\Events\ListenerHandled;
use RabbitEvents\Listener\Events\ListenerHandleFailed;
use RabbitEvents\Listener\Events\ListenerHandlerExceptionOccurred;
use RabbitEvents\Listener\Events\ListenerHandling;
use RabbitEvents\Listener\Contracts\Deduplicator;
use RabbitEvents\Listener\Deduplication\NullDeduplicator;
use RabbitEvents\Listener\Exceptions\FailedException;
use RabbitEvents\Listener\ListenerOptions;
use Throwable;

class Processor
{
    private Deduplicator $deduplicator;

    public function __construct(
        private HandlerFactory $handlerFactory,
        private EventsDispatcher $events,
        private Dispatcher $dispatcher,
        ?Deduplicator $deduplicator = null
    ) {
        $this->deduplicator = $deduplicator ?? new NullDeduplicator();
    }

    /**
     * Fire an event and call the listeners.
     *
     * @throws Throwable
     */
    public function process(Message $message, ListenerOptions $options): void
    {
        if ($this->deduplicator->isDuplicate($message, $options->queue())) {
            return;
        }

        $this->withContext($message, function () use ($message, $options) {
            $succeededListeners = (array) $message->getProperty('x-succeeded-listeners', []);

            foreach ($this->dispatcher->getListeners($message->event) as $listener) {
                $listenerId = $this->getListenerId($listener);

                if ($listenerId !== null && in_array($listenerId, $succeededListeners, true)) {
                    continue;
                }

                $response = $this->runHandler(
                    $this->handlerFactory->make($message, $listener, $listenerId ?? \Closure::class),
                    $options
                );

                if ($listenerId !== null) {
                    $succeededListeners[] = $listenerId;
                    $message->setProperty('x-succeeded-listeners', array_values(array_unique($succeededListeners)));
                }

                if ($response === false) {
                    break;
                }
            }
        });

        $this->deduplicator->markHandled($message, $options->queue());
    }

    protected function withContext(Message $message, callable $callback): mixed
    {
        if (!Facade::getFacadeApplication()) {
            return $callback();
        }

        $previous = Context::all();
        $previousHidden = Context::allHidden();

        if ($context = $message->getProperty('trace_context')) {
            Context::add((array) $context);
        }

        try {
            return $callback();
        } finally {
            Context::flush();

            if (!empty($previous)) {
                Context::add($previous);
            }

            if (!empty($previousHidden)) {
                Context::addHidden($previousHidden);
            }
        }
    }

    /**
     * Identifies a listener in `x-succeeded-listeners` as Class@method, so that methods of one subscriber are
     * tracked separately. It must be stable across processes. Closures can't be identified (null).
     */
    protected function getListenerId(mixed $listener): ?string
    {
        [$class, $method] = $this->getListenerCallback($listener);

        return $class === \Closure::class ? null : $class . '@' . ($method ?? 'handle');
    }

    /**
     * @return array{string, string|null} The listener class (\Closure::class for closures) and method
     */
    private function getListenerCallback(mixed $listener): array
    {
        if ($listener instanceof \Closure) {
            // Dispatcher::makeListener() wraps the registered listener into a closure.
            $used = (new \ReflectionFunction($listener))->getStaticVariables();

            return isset($used['listener']) && !$used['listener'] instanceof \Closure
                ? $this->getListenerCallback($used['listener'])
                : [\Closure::class, null];
        }

        if (is_string($listener)) {
            return \Illuminate\Support\Str::parseCallback($listener);
        }

        if (is_array($listener)) {
            return [is_object($listener[0]) ? get_class($listener[0]) : $listener[0], $listener[1] ?? null];
        }

        return is_object($listener) ? [get_class($listener), '__invoke'] : [\Closure::class, null];
    }

    /**
     * Process concrete listener
     *
     * @param Handler $handler
     * @param ListenerOptions $options
     * @throws Throwable
     */
    public function runHandler(Handler $handler, ListenerOptions $options)
    {
        try {
            $this->raiseBeforeEvent($handler);

            $startTime = hrtime(true);
            $response = $handler->handle();
            $duration = (hrtime(true) - $startTime) / 1e6;

            $this->raiseAfterEvent($handler, $duration);

            return $response;
        } catch (Throwable $e) {
            $this->handleException($handler, $options, $e);
        }
    }

    /**
     * Handle an exception that occurred while the job was running.
     *
     * @param Handler $handler
     * @param ListenerOptions $options
     * @param Throwable $exception
     * @return void
     *
     * @throws Throwable
     */
    protected function handleException(Handler $handler, ListenerOptions $options, Throwable $exception): void
    {
        try {
            if ($exception instanceof FailedException) {
                $this->handleFail($handler, $exception);
            }

            // First, we will go ahead and mark the job as failed if it will exceed the maximum
            // attempts it is allowed to run the next time we process it. If so we will just
            // go ahead and mark it as failed now, so we do not have to release this again.
            if (!$handler->hasFailed()) {
                $this->markAsFailedIfWillExceedMaxAttempts($handler, $options->maxTries, $exception);
            }

            $this->raiseExceptionOccurredEvent($handler, $exception);
        } finally {
            // If we catch an exception, we will attempt to release the job back onto the queue
            // so it is not lost entirely. This'll let the job be retried at a later time by
            // another listener (or this same one). We will re-throw this exception after.
            if ($this->couldBeReleased($handler)) {
                $handler->release($options->sleep);
            }
        }

        throw $exception;
    }

    protected function couldBeReleased(Handler $handler)
    {
        return !$handler->isReleased() && !$handler->hasFailed();
    }

    /**
     * Mark the given job as failed if it has exceeded the maximum allowed attempts.
     *
     * @param Handler $handler
     * @param int $maxTries
     * @param Throwable $exception
     * @return void
     */
    protected function markAsFailedIfWillExceedMaxAttempts(Handler $handler, int $maxTries, Throwable $exception): void
    {
        if ($maxTries > 0 && $handler->attempts() >= $maxTries) {
            $this->handleFail($handler, $exception);
        }
    }

    protected function handleFail(Handler $handler, Throwable $exception): void
    {
        try {
            $handler->fail($exception);
        } finally {
            $this->raiseFailedHandleEvent($handler, $exception);
        }
    }

    /**
     * Raise the before queue job event.
     */
    protected function raiseBeforeEvent(Handler $handler): void
    {
        $this->events->dispatch(new ListenerHandling($handler));
    }

    /**
     * Raise the after queue job event.
     */
    protected function raiseAfterEvent(Handler $handler, ?float $duration = null): void
    {
        $this->events->dispatch(new ListenerHandled($handler, $duration));
    }

    /**
     * Raise the exception occurred queue job event.
     *
     * @param Handler $handler
     * @param Throwable $exception
     * @return void
     */
    protected function raiseExceptionOccurredEvent(Handler $handler, Throwable $exception): void
    {
        $this->events->dispatch(new ListenerHandlerExceptionOccurred($handler, $exception));
    }

    /**
     * Raise the failed queue job event.
     */
    protected function raiseFailedHandleEvent(Handler $handler, Throwable $exception): void
    {
        $this->events->dispatch(new ListenerHandleFailed($handler, $exception));
    }
}
