<?php

namespace RabbitEvents\Tests\Foundation\Stubs;

use RabbitEvents\Foundation\Contracts\QueueConsumer;
use RabbitEvents\Foundation\Contracts\TransportMessage;

class QueueConsumerStub implements QueueConsumer
{
    /** @var TransportMessage[] */
    public array $messages = [];
    
    public array $acknowledged = [];
    public array $rejected = [];

    public function __construct(array $messages = [])
    {
        $this->messages = $messages;
    }

    public function receive(int $timeout = 0): ?TransportMessage
    {
        return array_shift($this->messages);
    }

    public function acknowledge(TransportMessage $message): void
    {
        $this->acknowledged[] = $message;
    }

    public function reject(TransportMessage $message, bool $requeue = false): void
    {
        $this->rejected[] = ['message' => $message, 'requeue' => $requeue];

        if ($requeue) {
            array_unshift($this->messages, $message);
        }
    }
    
    public function addMessage(TransportMessage $message): void
    {
        $this->messages[] = $message;
    }
}
