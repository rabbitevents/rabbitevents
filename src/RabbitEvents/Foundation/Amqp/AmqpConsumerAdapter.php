<?php

declare(strict_types=1);

namespace RabbitEvents\Foundation\Amqp;

use Interop\Amqp\AmqpConsumer;
use RabbitEvents\Foundation\Contracts\QueueConsumer;
use RabbitEvents\Foundation\Contracts\TransportMessage;

class AmqpConsumerAdapter implements QueueConsumer
{
    public function __construct(private AmqpConsumer $consumer)
    {
    }

    public function receive(int $timeout = 0): ?TransportMessage
    {
        if ($message = $this->consumer->receive($timeout)) {
            return new AmqpTransportMessage($message);
        }

        return null;
    }

    public function acknowledge(TransportMessage $message): void
    {
        $this->consumer->acknowledge($message->getOrigin());
    }

    public function reject(TransportMessage $message, bool $requeue = false): void
    {
        $this->consumer->reject($message->getOrigin(), $requeue);
    }

    public function requeue(array $messages): void
    {
        // RabbitMQ puts a requeued message at the head of the queue, so the last one goes back first.
        foreach (array_reverse($messages) as $message) {
            $this->reject($message, true);
        }
    }

    public function __call(string $method, array $args)
    {
        return $this->consumer->$method(...$args);
    }
}
