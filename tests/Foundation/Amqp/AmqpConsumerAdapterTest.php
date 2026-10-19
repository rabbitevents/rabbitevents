<?php

declare(strict_types=1);

namespace RabbitEvents\Tests\Foundation\Amqp;

use Interop\Amqp\AmqpConsumer;
use Interop\Amqp\Impl\AmqpMessage;
use Mockery as m;
use RabbitEvents\Foundation\Amqp\AmqpConsumerAdapter;
use RabbitEvents\Foundation\Amqp\AmqpTransportMessage;
use RabbitEvents\Tests\Foundation\TestCase;

class AmqpConsumerAdapterTest extends TestCase
{
    public function testRequeueRejectsInReverseOrderBecauseRabbitMqRequeuesToTheHead(): void
    {
        $first = new AmqpMessage('first');
        $second = new AmqpMessage('second');
        $rejected = [];

        $consumer = m::mock(AmqpConsumer::class);
        $consumer->shouldReceive('reject')->twice()->andReturnUsing(function ($message, $requeue) use (&$rejected) {
            $rejected[] = [$message->getBody(), $requeue];
        });

        (new AmqpConsumerAdapter($consumer))->requeue([new AmqpTransportMessage($first), new AmqpTransportMessage($second)]);

        self::assertSame([['second', true], ['first', true]], $rejected);
    }
}
