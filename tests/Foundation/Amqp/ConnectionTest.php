<?php

namespace RabbitEvents\Tests\Foundation\Amqp;

use Enqueue\AmqpTools\RabbitMqDlxDelayStrategy;
use Enqueue\AmqpTools\DelayStrategy;
use Interop\Amqp\AmqpConnectionFactory;
use Interop\Amqp\AmqpContext;
use RabbitEvents\Foundation\Amqp\Connection;
use RabbitEvents\Tests\Foundation\TestCase;
use Mockery as m;

class ConnectionTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = new Connection(['exchange' => 'events', 'delay_strategy' => RabbitMqDlxDelayStrategy::class]);
    }

    public function testConnect(): void
    {
        self::assertInstanceOf(AmqpConnectionFactory::class, $this->connection->connect());
    }

    public function testDelayStrategySetter(): void
    {
        $strategy = \Mockery::mock(DelayStrategy::class);
        $this->connection->setDelayStrategy($strategy);

        self::assertSame($strategy, $this->connection->getDelayStrategy());
    }

    public function testGetDelayStrategy(): void
    {
        self::assertInstanceOf(DelayStrategy::class, $this->connection->getDelayStrategy());
    }

    public function testGetConfig()
    {
        self::assertEquals('events', $this->connection->getConfig('exchange'));
    }

    public function testMakeFailedQueue(): void
    {
        $queue = new \Interop\Amqp\Impl\AmqpQueue('test.queue.failed');
        $context = m::mock(AmqpContext::class);
        $context->shouldReceive('createQueue')->with('test.queue.failed')->andReturn($queue);
        $context->shouldReceive('declareQueue')->with($queue)->once();

        $conn = new class(['exchange' => 'events', 'durable' => true], $context) extends Connection {
            public function __construct(array $config, private $mockContext)
            {
                parent::__construct($config);
            }

            public function createContext(): AmqpContext
            {
                return $this->mockContext;
            }
        };

        $failedDestination = $conn->makeFailedQueue('test.queue');

        self::assertInstanceOf(\RabbitEvents\Foundation\Contracts\FailedDestination::class, $failedDestination);
        self::assertSame('test.queue', $failedDestination->getOriginalQueueName());
        self::assertSame($queue, $failedDestination->getOrigin());
    }

    /**
     * Messages are published straight to the failed queue. If it was deleted while the worker runs, RabbitMQ drops
     * them (unroutable) and the original is acknowledged: it is declared again before each message.
     */
    public function testFailedQueueIsDeclaredBeforeEachMessageSentToIt(): void
    {
        $failedQueue = new \Interop\Amqp\Impl\AmqpQueue('test.queue.failed');
        $queue = new \Interop\Amqp\Impl\AmqpQueue('test.queue');
        $message = new \Interop\Amqp\Impl\AmqpMessage('{}');
        $producer = m::mock(\Interop\Amqp\AmqpProducer::class);

        $context = m::mock(AmqpContext::class);
        $context->shouldReceive('createQueue')->with('test.queue.failed')->andReturn($failedQueue);
        $context->shouldReceive('createQueue')->with('test.queue')->andReturn($queue);
        $context->shouldReceive('createProducer')->andReturn($producer);

        $context->shouldReceive('declareQueue')->with($queue)->once()->ordered();
        $context->shouldReceive('declareQueue')->with($failedQueue)->once()->ordered();
        $context->shouldReceive('declareQueue')->with($failedQueue)->once()->ordered();
        $producer->shouldReceive('send')->with($failedQueue, $message)->once()->ordered();
        $context->shouldReceive('declareQueue')->with($failedQueue)->once()->ordered();
        $producer->shouldReceive('send')->with($failedQueue, $message)->once()->ordered();
        // Other queues are not declared again: a released message goes to a queue the worker consumes.
        $producer->shouldReceive('send')->with($queue, $message)->once()->ordered();

        $conn = $this->connectionWithContext($context);
        $destination = new \RabbitEvents\Foundation\Amqp\AmqpDestinationAdapter(
            (new \RabbitEvents\Foundation\Amqp\QueueFactory($context, $conn))->makeAndDeclare('test.queue')
        );
        $failedDestination = $conn->makeFailedQueue('test.queue');
        $transportMessage = new \RabbitEvents\Foundation\Amqp\AmqpTransportMessage($message);

        $conn->createProducer()->send($failedDestination, $transportMessage);
        $conn->createProducer()->send($failedDestination, $transportMessage);
        $conn->createProducer()->send($destination, $transportMessage);
    }

    private function connectionWithContext(AmqpContext $context): Connection
    {
        return new class(['exchange' => 'events', 'durable' => true], $context) extends Connection {
            public function __construct(array $config, private $mockContext)
            {
                parent::__construct($config);
            }

            public function createContext(): AmqpContext
            {
                return $this->mockContext;
            }
        };
    }
}
