<?php

declare(strict_types=1);

namespace RabbitEvents\Tests\Foundation\Amqp;

use Interop\Amqp\AmqpProducer;
use Interop\Queue\Exception\DeliveryDelayNotSupportedException;
use Mockery as m;
use RabbitEvents\Foundation\Amqp\AmqpProducerAdapter;
use RabbitEvents\Tests\Foundation\TestCase;

class AmqpProducerAdapterTest extends TestCase
{
    public function testSetDelayPassesMillisecondsToTheProducer(): void
    {
        $producer = m::mock(AmqpProducer::class);
        $producer->shouldReceive('setDeliveryDelay')->once()->with(5000);

        (new AmqpProducerAdapter($producer))->setDelay(5);
    }

    public function testSetDelayIgnoresProducersWithoutDelayStrategy(): void
    {
        $producer = m::mock(AmqpProducer::class);
        $producer->shouldReceive('setDeliveryDelay')->andThrow(new DeliveryDelayNotSupportedException());

        (new AmqpProducerAdapter($producer))->setDelay(5);

        $this->addToAssertionCount(1);
    }
}
