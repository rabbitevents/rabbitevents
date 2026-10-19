<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\Message;


use Illuminate\Support\Arr;
use RabbitEvents\Listener\Support\Releaser;
use RabbitEvents\Foundation\Message;
use Throwable;

class Handler
{
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
        private mixed $failedCallback = null
    ) {
    }

    public function handle()
    {
        return ($this->listener)($this->message->event, Arr::wrap($this->payload()));
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

    /**
     * Returns the number of attempts to handle the message
     *
     * @return int
     */
    public function attempts(): int
    {
        return $this->message->attempts();
    }
}
