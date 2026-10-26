<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\Message;


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

    public function __construct(
        public readonly Message $message,
        private \Closure $listener,
        protected string $listenerClass,
        private Releaser $releaser,
        private mixed $failedCallback = null,
        private ?\Closure $listenerInstance = null
    ) {
    }

    public function handle()
    {
        $payload = $this->payload();

        // Like Arr::wrap(), but null stays a value: Arr::wrap(null) is [], which would call the listener without it.
        return ($this->listener)($this->message->event, is_array($payload) ? $payload : [$payload]);
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

        if ($this->failedCallback && is_callable($this->failedCallback)) {
            ($this->failedCallback)($this->payload(), $exception);
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
        $listener = $this->listener();

        if (method_exists($listener, 'tries')) {
            return (int) $listener->tries();
        }

        return isset($listener->tries) ? (int) $listener->tries : null;
    }

    /**
     * Seconds before the next try, from the listener's `backoff()` or `$backoff`; null if it declares none
     * (`--sleep` applies). An array gives the delay after each attempt, its last value applies to the rest.
     */
    public function backoff(): ?int
    {
        $listener = $this->listener();

        $backoff = method_exists($listener, 'backoff') ? $listener->backoff() : ($listener->backoff ?? null);

        if (is_array($backoff)) {
            $backoff = array_values($backoff);

            return $backoff === [] ? null : (int) ($backoff[$this->attempts() - 1] ?? end($backoff));
        }

        return is_null($backoff) ? null : (int) $backoff;
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

    /**
     * The listener instance that declares the retry policy. A listener that can't be built has none: its handling
     * failed with the same exception, which is reported.
     */
    private function listener(): object
    {
        try {
            return ($this->listenerInstance ? ($this->listenerInstance)() : null) ?? new \stdClass();
        } catch (Throwable) {
            return new \stdClass();
        }
    }
}
