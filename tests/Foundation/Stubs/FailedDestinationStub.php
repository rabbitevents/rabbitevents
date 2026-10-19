<?php

namespace RabbitEvents\Tests\Foundation\Stubs;

use RabbitEvents\Foundation\Contracts\FailedDestination;

class FailedDestinationStub extends DestinationStub implements FailedDestination
{
    public function __construct(private string $originalQueueName)
    {
        parent::__construct($originalQueueName . self::SUFFIX);
    }

    public function getOriginalQueueName(): string
    {
        return $this->originalQueueName;
    }
}
