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
}
