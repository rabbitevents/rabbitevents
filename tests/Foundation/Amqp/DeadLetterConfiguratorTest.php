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
use RabbitEvents\Foundation\Amqp\DeadLetterConfigurator;
use RabbitEvents\Tests\Foundation\TestCase;

class DeadLetterConfiguratorTest extends TestCase
{
    public function test_configures_dlx_and_failed_queue_when_enabled(): void
    {
        $queueName = 'my-service:events';
        $queue = new AmqpQueue($queueName);
        $failedQueue = new AmqpQueue("{$queueName}.failed");
        $dlxTopic = new AmqpTopic('rabbitevents.dlx');

        $context = m::mock(AmqpContext::class);
        $context->shouldReceive('createTopic')->with('rabbitevents.dlx')->andReturn($dlxTopic);
        $context->shouldReceive('declareTopic')->with($dlxTopic)->once();
        $context->shouldReceive('createQueue')->with("{$queueName}.failed")->andReturn($failedQueue);
        $context->shouldReceive('declareQueue')->with($failedQueue)->once();
        $context->shouldReceive('bind')->with(m::type(AmqpBind::class))->once();

        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getConfig')->with('dlx.enabled', true)->andReturn(true);
        $connection->shouldReceive('getConfig')->with('dlx.exchange', 'rabbitevents.dlx')->andReturn('rabbitevents.dlx');
        $connection->shouldReceive('getConfig')->with('durable', true)->andReturn(true);
        $connection->shouldReceive('getDeduplicationStrategy')->andReturn(new \RabbitEvents\Foundation\Deduplication\NullDeduplicator());

        $configurator = new DeadLetterConfigurator($context, $connection);
        $configurator->configure($queue, $queueName);

        self::assertNull($queue->getArgument('x-dead-letter-exchange'));
        self::assertNull($queue->getArgument('x-dead-letter-routing-key'));
        self::assertEquals(AmqpDestination::FLAG_DURABLE, $failedQueue->getFlags());
    }

    public function test_skips_configuration_when_disabled(): void
    {
        $queueName = 'my-service:events';
        $queue = new AmqpQueue($queueName);

        $context = m::mock(AmqpContext::class);
        $context->shouldNotReceive('createTopic');
        $context->shouldNotReceive('createQueue');

        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getConfig')->with('dlx.enabled', true)->andReturn(false);

        $configurator = new DeadLetterConfigurator($context, $connection);
        $configurator->configure($queue, $queueName);

        self::assertNull($queue->getArgument('x-dead-letter-exchange'));
    }

    public function test_skips_configuration_for_failed_queue(): void
    {
        $queueName = 'my-service:events.failed';
        $queue = new AmqpQueue($queueName);

        $context = m::mock(AmqpContext::class);
        $context->shouldNotReceive('createTopic');
        $context->shouldNotReceive('createQueue');

        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getConfig')->with('dlx.enabled', true)->andReturn(true);

        $configurator = new DeadLetterConfigurator($context, $connection);
        $configurator->configure($queue, $queueName);

        self::assertNull($queue->getArgument('x-dead-letter-exchange'));
    }
}
