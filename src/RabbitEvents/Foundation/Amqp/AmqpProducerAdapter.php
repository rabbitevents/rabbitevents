<?php

declare(strict_types=1);

namespace RabbitEvents\Foundation\Amqp;

use Interop\Amqp\AmqpProducer;
use Interop\Queue\Exception\DeliveryDelayNotSupportedException;
use RabbitEvents\Foundation\Contracts\DelaysDelivery;
use RabbitEvents\Foundation\Contracts\Destination;
use RabbitEvents\Foundation\Contracts\Producer;
use RabbitEvents\Foundation\Contracts\TransportMessage;

class AmqpProducerAdapter implements Producer, DelaysDelivery
{
    public function __construct(private AmqpProducer $producer)
    {
    }

    public function send(Destination $destination, TransportMessage $message): void
    {
        $this->producer->send($destination->getOrigin(), $message->getOrigin());
    }

    /**
     * @param int $delay Seconds; applies to the messages sent after this call.
     */
    public function setDelay(int $delay = 0): void
    {
        try {
            $this->producer->setDeliveryDelay($delay * 1000);
        } catch (DeliveryDelayNotSupportedException) {
            // Without a delay strategy the message is sent right away.
        }
    }

    public function __call(string $method, array $args)
    {
        return $this->producer->$method(...$args);
    }
}
