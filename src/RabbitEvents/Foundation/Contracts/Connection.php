<?php

declare(strict_types=1);

namespace RabbitEvents\Foundation\Contracts;

interface Connection
{
    public function createProducer(): Producer;

    public function makeConsumer(Destination $queue): QueueConsumer;

    public function makeTopic(): Destination;

    public function makeQueue(string $queueName, array $events, Destination $topic): Destination;

    /**
     * Declare the queue for messages that failed in `$queueName`. It is not bound to any event.
     */
    public function makeFailedQueue(string $queueName): FailedDestination;
}
