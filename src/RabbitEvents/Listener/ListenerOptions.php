<?php

declare(strict_types=1);

namespace RabbitEvents\Listener;

class ListenerOptions
{
    public function __construct(
        public readonly string $service,
        public readonly string $connectionName,
        public readonly array $events,
        public readonly int $memory = 128,
        public readonly int $maxTries = 0,
        public readonly int $timeout = 60,
        public readonly int $sleep = 5,
        private readonly ?string $queue = null,
    ) {
    }

    /**
     * The queue the worker consumes: the `--queue` option, or one derived from the service and events.
     */
    public function queue(): string
    {
        return $this->queue ?: QueueName::resolve($this->service, $this->events);
    }
}
