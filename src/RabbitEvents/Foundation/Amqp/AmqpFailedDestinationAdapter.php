<?php

declare(strict_types=1);

namespace RabbitEvents\Foundation\Amqp;

use Interop\Amqp\AmqpQueue;
use Interop\Queue\Destination as InteropDestination;
use RabbitEvents\Foundation\Contracts\FailedDestination;

class AmqpFailedDestinationAdapter extends AmqpDestinationAdapter implements FailedDestination
{
    public function __construct(
        InteropDestination $origin,
        private readonly string $originalQueueName,
        private readonly ?QueueFactory $queueFactory = null
    ) {
        parent::__construct($origin);
    }

    /**
     * Messages are published straight to the failed queue, through the default exchange. If it was deleted while the
     * worker runs, RabbitMQ would drop them as unroutable, and the original would be acknowledged: AmqpProducerAdapter
     * declares it again before each message, as the delay strategy declares its delay queue.
     */
    public function declare(): void
    {
        $origin = $this->getOrigin();

        if ($origin instanceof AmqpQueue) {
            $this->queueFactory?->declare($origin);
        }
    }

    public function getOriginalQueueName(): string
    {
        return $this->originalQueueName;
    }
}
