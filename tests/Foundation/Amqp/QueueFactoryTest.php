<?php

declare(strict_types=1);

namespace RabbitEvents\Tests\Foundation\Amqp;

use Interop\Amqp\AmqpContext;
use Interop\Amqp\AmqpDestination;
use Interop\Amqp\Impl\AmqpBind;
use Interop\Amqp\Impl\AmqpQueue;
use Interop\Amqp\Impl\AmqpTopic;
use Mockery as m;
use RabbitEvents\Foundation\Amqp\Connection;
use RabbitEvents\Foundation\Amqp\QueueFactory;
use RabbitEvents\Foundation\Deduplication\NullDeduplicator;
use RabbitEvents\Foundation\Deduplication\RabbitMqDeduplicationPluginStrategy;
use RabbitEvents\Listener\QueueName;
use RabbitEvents\Tests\Foundation\TestCase;

class QueueFactoryTest extends TestCase
{
    private array $events = ['item.created', 'item.updated'];

    public function test_make_queue_durable_with_dlx(): void
    {
        $resolvedQueueName = QueueName::resolve('rabbitevents-app', $this->events);
        $amqpQueue = new AmqpQueue($resolvedQueueName);
        $dlqQueue = new AmqpQueue("{$resolvedQueueName}.failed");
        $dlxTopic = new AmqpTopic('rabbitevents.dlx');

        $amqpContext = m::mock(AmqpContext::class);
        $amqpContext->shouldReceive('createQueue')->with($resolvedQueueName)->andReturn($amqpQueue);
        $amqpContext->shouldReceive('createQueue')->with("{$resolvedQueueName}.failed")->andReturn($dlqQueue);
        $amqpContext->shouldReceive('createTopic')->with('rabbitevents.dlx')->andReturn($dlxTopic);
        $amqpContext->shouldReceive('declareTopic')->with($dlxTopic)->once();
        $amqpContext->shouldReceive('declareQueue')->with($dlqQueue)->once();
        $amqpContext->shouldReceive('bind')->with(m::type(AmqpBind::class))->once();
        $amqpContext->shouldReceive('declareQueue')->with($amqpQueue)->once();

        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getConfig')->with('durable', true)->andReturn(true);
        $connection->shouldReceive('getConfig')->with('dlx.enabled', true)->andReturn(true);
        $connection->shouldReceive('getConfig')->with('dlx.exchange', 'rabbitevents.dlx')->andReturn('rabbitevents.dlx');
        $connection->shouldReceive('getDeduplicationStrategy')->andReturn(new NullDeduplicator());

        $factory = new QueueFactory($amqpContext, $connection);

        $queue = $factory->makeAndDeclare($resolvedQueueName);

        self::assertInstanceOf(AmqpQueue::class, $queue);
        self::assertEquals(AmqpDestination::FLAG_DURABLE, $queue->getFlags());
        self::assertNull($queue->getArgument('x-dead-letter-exchange'));
        self::assertNull($queue->getArgument('x-dead-letter-routing-key'));
    }

    public function test_make_queue_transient_without_dlx(): void
    {
        $resolvedQueueName = QueueName::resolve('rabbitevents-app', $this->events);
        $amqpQueue = new AmqpQueue($resolvedQueueName);

        $amqpContext = m::mock(AmqpContext::class);
        $amqpContext->shouldReceive('createQueue')->with($resolvedQueueName)->andReturn($amqpQueue);
        $amqpContext->shouldReceive('declareQueue')->with($amqpQueue)->once();

        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getConfig')->with('durable', true)->andReturn(false);
        $connection->shouldReceive('getConfig')->with('dlx.enabled', true)->andReturn(false);
        $connection->shouldReceive('getDeduplicationStrategy')->andReturn(new NullDeduplicator());

        $factory = new QueueFactory($amqpContext, $connection);

        $queue = $factory->makeAndDeclare($resolvedQueueName);

        self::assertEquals(AmqpDestination::FLAG_NOPARAM, $queue->getFlags());
        self::assertNull($queue->getArgument('x-dead-letter-exchange'));
    }

    public function test_make_failed_queue_does_not_declare_dlx(): void
    {
        $queueName = 'my-service:events.failed';
        $amqpQueue = new AmqpQueue($queueName);

        $amqpContext = m::mock(AmqpContext::class);
        $amqpContext->shouldReceive('createQueue')->with($queueName)->andReturn($amqpQueue);
        $amqpContext->shouldReceive('declareQueue')->with($amqpQueue)->once();

        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getConfig')->with('durable', true)->andReturn(true);
        $connection->shouldReceive('getConfig')->with('dlx.enabled', true)->andReturn(true);
        $connection->shouldReceive('getDeduplicationStrategy')->andReturn(new NullDeduplicator());

        $factory = new QueueFactory($amqpContext, $connection);

        $queue = $factory->makeAndDeclare($queueName);

        self::assertEquals(AmqpDestination::FLAG_DURABLE, $queue->getFlags());
        self::assertNull($queue->getArgument('x-dead-letter-exchange'));
    }

    public function test_make_queue_with_deduplication_strategy(): void
    {
        $resolvedQueueName = QueueName::resolve('rabbitevents-app', $this->events);
        $amqpQueue = new AmqpQueue($resolvedQueueName);

        $amqpContext = m::mock(AmqpContext::class);
        $amqpContext->shouldReceive('createQueue')->with($resolvedQueueName)->andReturn($amqpQueue);
        $amqpContext->shouldReceive('declareQueue')->with($amqpQueue)->once();

        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getConfig')->with('durable', true)->andReturn(true);
        $connection->shouldReceive('getConfig')->with('dlx.enabled', true)->andReturn(false);
        $connection->shouldReceive('getDeduplicationStrategy')->andReturn(new RabbitMqDeduplicationPluginStrategy());

        $factory = new QueueFactory($amqpContext, $connection);

        $queue = $factory->makeAndDeclare($resolvedQueueName);

        self::assertTrue($queue->getArgument('x-message-deduplication'));
    }
}

