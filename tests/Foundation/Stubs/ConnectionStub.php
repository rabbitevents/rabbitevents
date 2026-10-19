<?php

namespace RabbitEvents\Tests\Foundation\Stubs;

use RabbitEvents\Foundation\Contracts\Connection;
use RabbitEvents\Foundation\Contracts\Destination;
use RabbitEvents\Foundation\Contracts\Producer;
use RabbitEvents\Foundation\Contracts\QueueConsumer;

class ConnectionStub implements Connection
{
    public array $createdConsumers = [];
    public int $createdProducers = 0;
    public array $createdTopics = [];
    public array $createdQueues = [];
    public ?Producer $producer = null;
    public ?QueueConsumer $consumer = null;
    
    public function createProducer(): Producer
    {
        $this->createdProducers++;
        return $this->producer ?? new ProducerStub();
    }

    public function makeConsumer(Destination $queue): QueueConsumer
    {
        $this->createdConsumers[] = $queue;
        return $this->consumer ?? new QueueConsumerStub();
    }

    public function makeTopic(): Destination
    {
        $this->createdTopics[] = 'topic';
        return new DestinationStub('topic');
    }

    public function makeQueue(string $queueName, array $events, Destination $topic): Destination
    {
        $this->createdQueues[] = [
            'name' => $queueName,
            'events' => $events,
            'topic' => $topic
        ];
        
        return new DestinationStub($queueName);
    }

    public function makeFailedQueue(string $queueName): \RabbitEvents\Foundation\Contracts\FailedDestination
    {
        $this->createdQueues[] = [
            'name' => $queueName . \RabbitEvents\Foundation\Contracts\FailedDestination::SUFFIX,
            'is_failed' => true,
        ];

        return new FailedDestinationStub($queueName);
    }
}
