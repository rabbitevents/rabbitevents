<?php

declare(strict_types=1);

namespace RabbitEvents\Listener;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher as EventsDispatcher;
use Illuminate\Support\InteractsWithTime;
use RabbitEvents\Foundation\Consumer;
use RabbitEvents\Foundation\Contracts\Transport;
use RabbitEvents\Foundation\Exceptions\ConnectionLostException;
use RabbitEvents\Foundation\Message;
use RabbitEvents\Listener\Events\KillSwitchRestarted;
use RabbitEvents\Listener\Events\KillSwitchTripped;
use RabbitEvents\Listener\Events\MessageProcessingFailed;
use RabbitEvents\Listener\Events\WorkerStopping;
use RabbitEvents\Listener\Exceptions\TimeoutExceededException;
use RabbitEvents\Listener\Message\Processor;
use RabbitEvents\Listener\Support\KillSwitch;
use RabbitEvents\Listener\Support\Releaser;
use Throwable;

class Worker
{
    use InteractsWithTime;

    /**
     * Indicates if the worker should exit.
     */
    public bool $shouldQuit = false;

    /**
     * A message went back to the queue as it was received (see requeue()): the worker must stop.
     */
    protected bool $requeued = false;

    protected ?Transport $failer = null;

    protected ?Releaser $releaser = null;

    protected ?KillSwitch $killSwitch = null;

    public function __construct(
        private ExceptionHandler $exceptions,
        private EventsDispatcher $events
    ) {
    }

    public function setFailer(?Transport $failer): self
    {
        $this->failer = $failer;

        return $this;
    }

    /**
     * Used to return a message whose processing was interrupted outside its listeners.
     */
    public function setReleaser(?Releaser $releaser): self
    {
        $this->releaser = $releaser;

        return $this;
    }

    public function setKillSwitch(?KillSwitch $killSwitch): self
    {
        $this->killSwitch = $killSwitch;

        return $this;
    }

    /**
     * @throws Throwable
     */
    public function work(Processor $processor, Consumer $consumer, ListenerOptions $options): WorkerExitStatus
    {
        if ($supportsAsyncSignals = $this->supportsAsyncSignals()) {
            $this->listenForSignals();
        }

        while (true) {
            $tripped = false;

            if ($message = $this->getNextMessage($consumer)) {
                if ($supportsAsyncSignals) {
                    $this->registerTimeoutHandler($message, $consumer, $options);
                }

                $interruption = $this->processMessage($processor, $message, $options);

                if ($supportsAsyncSignals) {
                    $this->resetTimeoutHandler();
                }

                if ($message->hasFailed()) {
                    $this->failOrRequeue($message, $consumer);
                } elseif (!$interruption || $message->isReleased()) {
                    $consumer->acknowledge($message);
                } else {
                    $this->returnInterruptedMessage($message, $consumer, $options, $interruption);
                }

                $tripped = (bool) $this->killSwitch?->record(failed: $interruption !== null);
            }

            $status = $this->stopIfNecessary($options);

            // A worker that must stop anyway doesn't pause first, nor fetch a message after the pause.
            if (is_null($status) && $tripped) {
                $this->pauseUntilRestart($this->killSwitch);
                $status = $this->stopIfNecessary($options);
            }

            if (!is_null($status)) {
                return $this->stop($status);
            }
        }
    }

    /**
     * @param Consumer $consumer
     * @return Message|null
     */
    protected function getNextMessage(Consumer $consumer): ?Message
    {
        try {
            return $consumer->nextMessage(1000);
        } catch (Throwable $throwable) {
            $this->exceptions->report($throwable);

            $this->stopListeningIfLostConnection($throwable);
        }

        return null;
    }

    /**
     * @param Processor $processor
     * @param Message $message
     * @param ListenerOptions $options
     * @return Throwable|null What processing threw
     */
    private function processMessage(Processor $processor, Message $message, ListenerOptions $options): ?Throwable
    {
        try {
            $processor->process($message, $options);

            return null;
        } catch (Throwable $throwable) {
            $this->exceptions->report($throwable);

            return $throwable;
        }
    }

    /**
     * Processing threw outside the listeners (a failing listener releases the message itself), so the
     * message was not handled completely and must not be acknowledged as handled. A released copy keeps
     * `x-succeeded-listeners`, so listeners that already handled it skip it. Requeueing the original is
     * the fallback, and it stops the worker (see requeue()).
     * On its `--tries`th delivery the message fails instead, so that an interruption can't loop forever.
     */
    protected function returnInterruptedMessage(
        Message $message,
        Consumer $consumer,
        ListenerOptions $options,
        Throwable $interruption
    ): void {
        if ($options->maxTries > 0 && $message->attempts() >= $options->maxTries) {
            $message->markAsFailed($interruption);
            $this->events->dispatch(new MessageProcessingFailed($message, $interruption));
            $this->failOrRequeue($message, $consumer);

            return;
        }

        try {
            $this->releaser?->release($message, $options->sleep);
        } catch (Throwable $throwable) {
            $this->exceptions->report($throwable);
        }

        $message->isReleased() ? $consumer->acknowledge($message) : $this->requeue($message, $consumer);
    }

    /**
     * A message that can't be moved to the failed queue (the broker refuses the publish) is not lost: it goes back
     * to its queue.
     */
    protected function failOrRequeue(Message $message, Consumer $consumer): void
    {
        try {
            $this->failMessage($message, $consumer);
        } catch (Throwable $throwable) {
            $this->exceptions->report($throwable);

            $this->requeue($message, $consumer);
        }
    }

    /**
     * Return the message as it was received, when no copy of it could be published. Its attempts, the listeners that
     * handled it and its failure aren't recorded in it, and RabbitMQ puts it at the head of the queue: this worker
     * would handle it again at once, forever, running even the listeners that had handled it, and `--tries` could
     * never stop it. So the worker stops; the process manager restarts it later.
     */
    protected function requeue(Message $message, Consumer $consumer): void
    {
        $consumer->reject($message, true);

        $this->requeued = true;
    }

    /**
     * Stop consuming while the kill switch is tripped. Messages stay in the queue meanwhile: the AMQP consumer
     * fetches them one by one, so none is held unacknowledged. A stopped worker (SIGTERM) quits at once.
     */
    protected function pauseUntilRestart(KillSwitch $killSwitch): void
    {
        $this->events->dispatch(new KillSwitchTripped($killSwitch->restartTimeout));

        $restartAt = $this->currentTime() + $killSwitch->restartTimeout;

        while (!$this->shouldQuit && $this->currentTime() < $restartAt) {
            $this->sleep(1);
        }

        if ($this->shouldQuit) {
            return;
        }

        $killSwitch->restart();
        $this->events->dispatch(new KillSwitchRestarted());
    }

    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * Register the worker timeout handler.
     */
    protected function registerTimeoutHandler(Message $message, Consumer $consumer, ListenerOptions $options): void
    {
        // We will register a signal handler for the alarm signal so that we can kill this
        // process if it is running too long because it has frozen. This uses the async
        // signals supported in recent versions of PHP to accomplish it conveniently.
        pcntl_signal(SIGALRM, function () use ($message, $consumer) {
            $e = new TimeoutExceededException('Worker has timed out.');
            $this->events->dispatch(new MessageProcessingFailed($message, $e));

            $message->markAsFailed($e);

            try {
                $this->failMessage($message, $consumer);
            } finally {
                // The worker is stuck: stop it even if the broker can't be reached to fail the message.
                $this->kill(WorkerExitStatus::ERROR);
            }
        });

        pcntl_alarm(max($options->timeout, 0));
    }

    protected function failMessage(Message $message, Consumer $consumer): void
    {
        if ($this->failer) {
            $this->failer->send($message);
            $consumer->acknowledge($message);
        } else {
            $consumer->reject($message, false);
        }
    }

    /**
     * Reset the worker timeout handler.
     */
    protected function resetTimeoutHandler()
    {
        pcntl_alarm(0);
    }

    /**
     * @param $throwable
     */
    protected function stopListeningIfLostConnection($throwable): void
    {
        if ($throwable instanceof ConnectionLostException) {
            $this->shouldQuit = true;
        }
    }

    /**
     * Stop the process if necessary.
     *
     * @return null|int
     */
    protected function stopIfNecessary(ListenerOptions $options): ?WorkerExitStatus
    {
        if ($this->requeued) {
            return WorkerExitStatus::ERROR;
        }

        if ($this->shouldQuit) {
            return WorkerExitStatus::SUCCESS;
        }

        if ($this->memoryExceeded($options->memory)) {
            return WorkerExitStatus::MEMORY_LIMIT;
        }

        return null;
    }

    /**
     * Determine if the memory limit has been exceeded.
     *
     * @param int $memoryLimit
     * @return bool
     */
    protected function memoryExceeded(int $memoryLimit): bool
    {
        return (memory_get_usage(true) / 1024 / 1024) >= $memoryLimit;
    }

    /**
     * Determine if "async" signals are supported.
     *
     * @return bool
     */
    protected function supportsAsyncSignals(): bool
    {
        return extension_loaded('pcntl');
    }

    /**
     * Stop listening and bail out of the script.
     *
     * @param WorkerExitStatus $status
     * @return WorkerExitStatus
     */
    public function stop(WorkerExitStatus $status): WorkerExitStatus
    {
        $this->events->dispatch(new WorkerStopping($status->value));

        return $status;
    }

    /**
     * Kill the process.
     *
     * @param int|WorkerExitStatus $status
     * @return never
     */
    public function kill(int|WorkerExitStatus $status = 0): void
    {
        $exitCode = $status instanceof WorkerExitStatus ? $status->value : $status;

        $this->events->dispatch(new WorkerStopping($exitCode));

        if (extension_loaded('posix')) {
            posix_kill(getmypid(), SIGKILL);
        }

        exit($exitCode);
    }

    /**
     * Enable async signals for the process.
     *
     * @return void
     */
    protected function listenForSignals(): void
    {
        pcntl_async_signals(true);

        pcntl_signal(SIGINT, function () {
            $this->kill(WorkerExitStatus::SUCCESS);
        });

        pcntl_signal(SIGTERM, function () {
            $this->shouldQuit = true;
        });
    }
}
