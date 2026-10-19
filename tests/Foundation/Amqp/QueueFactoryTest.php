<?php

declare(strict_types=1);

namespace RabbitEvents\Tests\Foundation\Amqp;

use Interop\Amqp\AmqpContext;
use Interop\Amqp\AmqpDestination;
use Interop\Amqp\Impl\AmqpQueue;
use Mockery as m;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use RabbitEvents\Foundation\Amqp\Connection;
use RabbitEvents\Foundation\Amqp\QueueFactory;
use RabbitEvents\Foundation\Exceptions\QueueArgumentsMismatchException;
use RabbitEvents\Tests\Foundation\TestCase;

class QueueFactoryTest extends TestCase
{
    public function test_declares_durable_queue_without_arguments(): void
    {
        $amqpQueue = new AmqpQueue('rabbitevents-app:item.created');

        $amqpContext = m::mock(AmqpContext::class);
        $amqpContext->shouldReceive('createQueue')->with('rabbitevents-app:item.created')->andReturn($amqpQueue);
        $amqpContext->shouldReceive('declareQueue')->with($amqpQueue)->once();

        $queue = (new QueueFactory($amqpContext, $this->connection(durable: true)))
            ->makeAndDeclare('rabbitevents-app:item.created');

        self::assertSame(AmqpDestination::FLAG_DURABLE, $queue->getFlags());
        // Same declaration as RabbitEvents 9.x, so both versions can share a queue.
        self::assertSame([], $queue->getArguments());
    }

    public function test_declares_transient_queue(): void
    {
        $amqpQueue = new AmqpQueue('rabbitevents-app:item.created');

        $amqpContext = m::mock(AmqpContext::class);
        $amqpContext->shouldReceive('createQueue')->andReturn($amqpQueue);
        $amqpContext->shouldReceive('declareQueue')->with($amqpQueue)->once();

        $queue = (new QueueFactory($amqpContext, $this->connection(durable: false)))
            ->makeAndDeclare('rabbitevents-app:item.created');

        self::assertSame(AmqpDestination::FLAG_NOPARAM, $queue->getFlags());
    }

    public function test_explains_how_to_fix_406_when_existing_queue_was_declared_differently(): void
    {
        $amqpContext = m::mock(AmqpContext::class);
        $amqpContext->shouldReceive('createQueue')->andReturn(new AmqpQueue('rabbitevents-app:item.created'));
        $amqpContext->shouldReceive('declareQueue')->andThrow(new AMQPProtocolChannelException(
            406,
            "PRECONDITION_FAILED - inequivalent arg 'durable'",
            [50, 10]
        ));

        $this->expectException(QueueArgumentsMismatchException::class);
        $this->expectExceptionMessage('rabbitevents-app:item.created');

        (new QueueFactory($amqpContext, $this->connection(durable: true)))->makeAndDeclare('rabbitevents-app:item.created');
    }

    public function test_rethrows_other_channel_errors(): void
    {
        $amqpContext = m::mock(AmqpContext::class);
        $amqpContext->shouldReceive('createQueue')->andReturn(new AmqpQueue('q'));
        $amqpContext->shouldReceive('declareQueue')->andThrow(new AMQPProtocolChannelException(403, 'ACCESS_REFUSED', [50, 10]));

        $this->expectException(AMQPProtocolChannelException::class);

        (new QueueFactory($amqpContext, $this->connection(durable: true)))->makeAndDeclare('q');
    }

    private function connection(bool $durable): Connection
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getConfig')->with('durable', true)->andReturn($durable);

        return $connection;
    }
}
