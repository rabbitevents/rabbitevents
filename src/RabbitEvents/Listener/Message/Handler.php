<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\Message;

use RabbitEvents\Listener\RegisteredListener;
use RabbitEvents\Listener\Support\Releaser;
use RabbitEvents\Foundation\Message;
use Throwable;

class Handler
{
    /**
     * Attempts of every listener of the message, as a list of [Class@method, attempts] pairs: AMQP table
     * keys are limited to 128 characters, listener ids are not.
     */
    public const ATTEMPTS_PROPERTY = 'x-listener-attempts';

    /**
     * Indicates if the handler has been released.
     *
     * @var bool
     */
    protected bool $released = false;

    /**
     * Indicates if the handle attempt has failed.
     *
     * @var bool
     */
    protected bool $failed = false;

    private \Closure $listener;

    /**
     * Gives the retry policy and failed() of the listener (null for a closure).
     */
    private ?RegisteredListener $registeredListener;

    /**
     * @param mixed $failedCallback Deprecated: failed() is called through RegisteredListener. It will be removed in 11.0.
     */
    public function __construct(
        public readonly Message $message,
        callable $listener,
        protected string $listenerClass,
        private Releaser $releaser,
        private mixed $failedCallback = null
    ) {
        $this->listener = \Closure::fromCallable($listener);
        $this->registeredListener = $listener instanceof RegisteredListener ? $listener : null;
    }

    public function handle()
    {
        return ($this->listener)($this->message->event, $this->listenerPayload());
    }

    public function payload(): mixed
    {
        return $this->message->payload->value();
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->message->event . ':' . $this->listenerClass;
    }

    /**
     * Delete the message, call the "failed" method, and raise the failed handler event.
     *
     * @param Throwable $exception
     * @return void
     */
    public function fail(Throwable $exception): void
    {
        $this->markAsFailed();
        $this->message->markAsFailed($exception, $this->listenerClass);

        if (is_callable($this->failedCallback)) {
            ($this->failedCallback)($this->payload(), $exception);
        } else {
            $this->registeredListener?->failed($this->payload(), $exception);
        }
    }

    /**
     * Determine if the handler has been marked as a failure.
     */
    public function hasFailed(): bool
    {
        return $this->failed;
    }

    /**
     * Mark the handler as "failed".
     */
    public function markAsFailed(): void
    {
        $this->failed = true;
    }

    /**
     * Release the message back into the queue.
     *
     * @param int $delay
     */
    public function release(int $delay = 0): void
    {
        $this->releaser->release($this->message, $delay);

        $this->released = true;
    }

    /**
     * Determine if the message was released back into the queue.
     */
    public function isReleased(): bool
    {
        return $this->released;
    }

    public function isClosure(): bool
    {
        return $this->listenerClass === \Closure::class;
    }

    /**
     * The number of times this listener was attempted on the message, the current attempt included once counted.
     * A closure can't be identified across deliveries, so for it this is the number of deliveries.
     */
    public function attempts(): int
    {
        if ($this->isClosure()) {
            return $this->message->attempts();
        }

        foreach ($this->listenerAttempts() as [$listener, $attempts]) {
            if ($listener === $this->listenerClass) {
                return (int) $attempts;
            }
        }

        return 0;
    }

    /**
     * Count an attempt of this listener in the message, so that a released message carries it.
     */
    public function countAttempt(): void
    {
        if ($this->isClosure()) {
            return;
        }

        $attempts = $this->attempts() + 1;
        $listenerAttempts = array_values(array_filter(
            $this->listenerAttempts(),
            fn(array $entry) => $entry[0] !== $this->listenerClass
        ));
        $listenerAttempts[] = [$this->listenerClass, $attempts];

        $this->message->setProperty(self::ATTEMPTS_PROPERTY, $listenerAttempts);
    }

    /**
     * The listener's `tries()` or `$tries`; null if it declares none (`--tries` applies).
     */
    public function tries(): ?int
    {
        return $this->registeredListener?->tries($this->message->event, $this->listenerPayload());
    }

    /**
     * Seconds before the next try, from the listener's `backoff()` or `$backoff`; null if it declares none
     * (`--sleep` applies). An array gives the delay after each attempt, its last value applies to the rest.
     */
    public function backoff(): ?int
    {
        return $this->registeredListener?->backoff($this->message->event, $this->listenerPayload(), $this->attempts());
    }

    /**
     * The payload the listener is called with. Like Arr::wrap(), but null stays a value: Arr::wrap(null) is [],
     * which would call the listener without it.
     */
    private function listenerPayload(): array
    {
        $payload = $this->payload();

        return is_array($payload) ? $payload : [$payload];
    }

    /**
     * @return list<array{string, int}>
     */
    private function listenerAttempts(): array
    {
        return array_values(array_filter(
            (array) $this->message->getProperty(self::ATTEMPTS_PROPERTY, []),
            static fn($entry) => is_array($entry) && is_string($entry[0] ?? null)
        ));
    }
}
