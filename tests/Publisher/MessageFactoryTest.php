<?php

namespace RabbitEvents\Tests\Publisher;

use Carbon\Carbon;
use RabbitEvents\Publisher\MessageFactory;
use RabbitEvents\Publisher\Support\AbstractPublishableEvent;
use RabbitEvents\Foundation\Support\JsonPayload;
use RabbitEvents\Foundation\Contracts\Transport;
use RabbitEvents\Foundation\Contracts\Payload;

class MessageFactoryTest extends TestCase
{
    private MessageFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $registry = \Mockery::mock(\RabbitEvents\Foundation\Serialization\SerializerRegistry::class);
        $registry->shouldReceive('get')->andReturn(\Mockery::mock(\RabbitEvents\Foundation\Contracts\Serializer::class));

        $this->factory = new MessageFactory($registry);

        Carbon::setTestNow(Carbon::createFromTimeString('2022-03-21 00:00:00'));
    }

    public function testMakeFromArray(): void
    {
        $payload = new JsonPayload([]);

        $serializer = \Mockery::mock(\RabbitEvents\Foundation\Contracts\Serializer::class);
        $serializer->shouldReceive('serialize')->andReturn($payload);
        $serializer->shouldReceive('contentType')->andReturn(new \RabbitEvents\Foundation\Serialization\JsonContentType());

        $registry = \Mockery::mock(\RabbitEvents\Foundation\Serialization\SerializerRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($serializer);
        
        $this->factory = new MessageFactory($registry);

        $message = $this->factory->make(new Event());

        self::assertEquals('some.event', $message->event);
        self::assertInstanceOf(Payload::class, $message->payload);
        self::assertEquals(Carbon::now()->getTimestamp(), $message->getTimestamp());
        self::assertNotNull($message->id());
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $message->id());
    }

    public function testMakeFromPayloadObject(): void
    {
        $payload = new JsonPayload(['pay' => 'load']);

        $event = new Event();
        $event->toPublish = $payload;

        $serializer = \Mockery::mock(\RabbitEvents\Foundation\Contracts\Serializer::class);
        $serializer->shouldReceive('serialize')->with($payload)->andReturn($payload);
        $serializer->shouldReceive('contentType')->andReturn(new \RabbitEvents\Foundation\Serialization\JsonContentType());

        $registry = \Mockery::mock(\RabbitEvents\Foundation\Serialization\SerializerRegistry::class);
        $registry->shouldReceive('resolve')->with($payload)->andReturn($serializer);

        $factory = new MessageFactory($registry);

        $message = $factory->make($event);

        self::assertSame($payload, $message->payload);
        self::assertEquals(Carbon::now()->getTimestamp(), $message->getTimestamp());
        self::assertNotNull($message->id());
    }

    public function testMakeSanitizesContextObjectsAndEncodesIntoAmqpTable(): void
    {
        if (!class_exists(\Illuminate\Support\Facades\Context::class)) {
            self::markTestSkipped('Context facade not available');
        }

        $app = new \Illuminate\Container\Container();
        $realRepository = new \Illuminate\Log\Context\Repository(new \Illuminate\Events\Dispatcher($app));
        $app->instance(\Illuminate\Log\Context\Repository::class, $realRepository);
        \Illuminate\Support\Facades\Facade::setFacadeApplication($app);

        try {
            \Illuminate\Support\Facades\Context::add([
                'scalar' => 'val',
                'obj' => (object) ['foo' => 'bar'],
                'json_obj' => new class implements \JsonSerializable {
                    public function jsonSerialize(): mixed { return ['serialized' => 123]; }
                },
                'stringable' => new class {
                    public function __toString(): string { return 'str-obj'; }
                },
                'model' => new class implements \Illuminate\Contracts\Queue\QueueableEntity, \JsonSerializable {
                    public function getQueueableId(): mixed { return 42; }
                    public function getQueueableRelations(): array { return []; }
                    public function getQueueableConnection(): ?string { return null; }
                    public function jsonSerialize(): mixed { return ['id' => 42, 'secret' => 'do-not-leak']; }
                },
            ]);

            $registry = new \RabbitEvents\Foundation\Serialization\SerializerRegistry();
            $registry->register(new \RabbitEvents\Foundation\Serialization\JsonSerializer());

            $factory = new MessageFactory($registry);
            $message = $factory->make(new Event());

            $traceContext = $message->getProperty('trace_context');
            self::assertEquals([
                'scalar' => 'val',
                'obj' => ['foo' => 'bar'],
                'json_obj' => ['serialized' => 123],
                'stringable' => 'str-obj',
            ], array_diff_key($traceContext, ['model' => true]));
            self::assertStringEndsWith(':42', $traceContext['model']);
            self::assertStringNotContainsString('do-not-leak', json_encode($traceContext));

            // Prove that real php-amqplib AMQPTable accepts the produced AMQP properties without throwing AMQPInvalidArgumentException
            $table = new \PhpAmqpLib\Wire\AMQPTable($message->transportMessage()->getProperties());
            self::assertArrayHasKey('trace_context', $table->getNativeData());
        } finally {
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication(null);
        }
    }
}

class Event extends AbstractPublishableEvent
{
    public $toPublish = ['pay' => 'load'];

    public function publishEventKey(): string
    {
        return 'some.event';
    }

    public function toPublish(): mixed
    {
        return $this->toPublish;
    }
}
