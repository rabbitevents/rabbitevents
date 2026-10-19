<?php

declare(strict_types=1);

namespace RabbitEvents\Foundation\Amqp;

use Interop\Queue\Destination as InteropDestination;
use RabbitEvents\Foundation\Contracts\FailedDestination;

class AmqpFailedDestinationAdapter extends AmqpDestinationAdapter implements FailedDestination
{
    public function __construct(InteropDestination $origin, private readonly string $originalQueueName)
    {
        parent::__construct($origin);
    }

    public function getOriginalQueueName(): string
    {
        return $this->originalQueueName;
    }
}
