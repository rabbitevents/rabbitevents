<?php

declare(strict_types=1);

namespace RabbitEvents\Foundation;

use RabbitEvents\Foundation\Contracts\Connection;
use RabbitEvents\Foundation\Contracts\Destination;
use RabbitEvents\Foundation\Contracts\Producer;

class Context
{
    public function __construct(
        public readonly Connection $connection,
        public readonly Serialization\SerializerRegistry $registry
    ) {
    }

    public function makeTopic(): Destination
    {
        return $this->connection->makeTopic();
    }

    public function createProducer(): Producer
    {
        return $this->connection->createProducer();
    }

    public function makeConsumer(Destination $queue): Consumer
    {
        return new Consumer(
            $this->connection->makeConsumer($queue),
            $this->registry
        );
    }

    public function makeQueue(string $queueName, array $events, Destination $topic): Destination
    {
        return $this->connection->makeQueue($queueName, $events, $topic);
    }

    public function makeFailedQueue(string $queueName): Contracts\FailedDestination
    {
        return $this->connection->makeFailedQueue($queueName);
    }
}
