<?php

namespace RabbitEvents\Tests\Publisher;

use Illuminate\Contracts\Support\Arrayable;
use RabbitEvents\Publisher\MessageFactory;
use RabbitEvents\Publisher\Publisher;
use RabbitEvents\Publisher\Support\AbstractPublishableEvent;
use RabbitEvents\Foundation\Message;

class PublisherTest extends TestCase
{
    private function makeRealPublisher(
        ?\RabbitEvents\Tests\Foundation\Stubs\ProducerStub &$producer = null,
        ?\RabbitEvents\Publisher\Contracts\Outbox $outbox = null,
        ?\Illuminate\Contracts\Events\Dispatcher $events = null
    ): Publisher {
        $registry = new \RabbitEvents\Foundation\Serialization\SerializerRegistry();
        $registry->register(new \RabbitEvents\Foundation\Serialization\JsonSerializer());

        $producer = new \RabbitEvents\Tests\Foundation\Stubs\ProducerStub();
        $sender = new \RabbitEvents\Foundation\Support\Sender(
            new \RabbitEvents\Tests\Foundation\Stubs\DestinationStub('events'),
            $producer
        );

        return new Publisher(new MessageFactory($registry), $sender, $outbox, $events);
    }

    /**
     * Laravel's event dispatcher, with a listener that records MessagePublished.
     *
     * @return array{\Illuminate\Events\Dispatcher, object{events: list<\RabbitEvents\Publisher\Events\MessagePublished>}}
     */
    private function events(): array
    {
        $events = new \Illuminate\Events\Dispatcher(new \Illuminate\Container\Container());
        $published = new class {
            public array $events = [];
        };
        $events->listen(\RabbitEvents\Publisher\Events\MessagePublished::class, function ($event) use ($published) {
            $published->events[] = $event;
        });

        return [$events, $published];
    }

    public function testPublish(): void
    {
        $event = new SomeEvent(new SomeModel(), ['foo' => 'bar'], 'Hello!');

        $publisher = $this->makeRealPublisher($producer);
        $result = $publisher->publish($event);

        self::assertIsString($result);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $result);
        self::assertCount(1, $producer->sent);
        self::assertSame($result, $producer->sent[0]['message']->getMessageId());
        self::assertSame('something.happened', $producer->sent[0]['message']->getRoutingKey());
    }

    public function testPublishAfterCommitDeferred(): void
    {
        $event = new SomeEventAfterCommit(new SomeModel(), ['foo' => 'bar'], 'Hello!');

        $deferredCallbacks = [];
        $transactionManager = new class($deferredCallbacks) {
            public function __construct(public array &$callbacks) {}
            public function addCallback(callable $callback): void {
                $this->callbacks[] = $callback;
            }
        };

        $publisher = $this->makeRealPublisher($producer)
            ->setTransactionManagerResolver(fn() => $transactionManager);
        $result = $publisher->publish($event);

        self::assertNull($result);
        self::assertCount(0, $producer->sent);
        self::assertCount(1, $deferredCallbacks);

        // Simulate transaction commit executing the registered callback
        ($deferredCallbacks[0])();
        self::assertCount(1, $producer->sent);
        self::assertSame('something.happened', $producer->sent[0]['message']->getRoutingKey());
    }

    public function testPublishAfterCommitImmediate(): void
    {
        $event = new SomeEventAfterCommit(new SomeModel(), ['foo' => 'bar'], 'Hello!');

        $transactionManager = new class {
            public function addCallback(callable $callback): void {
                // Outside an active transaction, Laravel DatabaseTransactionsManager executes callback immediately
                $callback();
            }
        };

        $publisher = $this->makeRealPublisher($producer)
            ->setTransactionManagerResolver(fn() => $transactionManager);
        $result = $publisher->publish($event);

        self::assertIsString($result);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $result);
        self::assertCount(1, $producer->sent);
        self::assertSame($result, $producer->sent[0]['message']->getMessageId());
    }

    public function testPublishAfterCommitViaProperty(): void
    {
        $event = (new SomeEvent(new SomeModel(), ['foo' => 'bar'], 'Hello!'))->afterCommit();

        $deferredCallbacks = [];
        $transactionManager = new class($deferredCallbacks) {
            public function __construct(public array &$callbacks) {}
            public function addCallback(callable $callback): void {
                $this->callbacks[] = $callback;
            }
        };

        $publisher = $this->makeRealPublisher($producer)
            ->setTransactionManagerResolver(fn() => $transactionManager);

        $result = $publisher->publish($event);

        self::assertNull($result);
        self::assertCount(0, $producer->sent);
        self::assertCount(1, $deferredCallbacks);
    }

    public function testMessageTakenByTheOutboxIsNotSent(): void
    {
        $event = new SomeEvent(new SomeModel(), ['foo' => 'bar'], 'Hello!');
        $outbox = new RecordingOutbox(takes: true);
        [$events, $published] = $this->events();

        $result = $this->makeRealPublisher($producer, $outbox, $events)->publish($event);

        self::assertCount(0, $producer->sent);
        self::assertCount(1, $outbox->stored);
        self::assertSame($event, $outbox->stored[0][0]);
        self::assertSame($result, $outbox->stored[0][1]->id());
        self::assertSame('something.happened', $outbox->stored[0][1]->event);
        // Published means sent to the broker: the outbox dispatches it when it sends the message.
        self::assertSame([], $published->events);
    }

    public function testMessageTheOutboxDeclinesIsSent(): void
    {
        $outbox = new RecordingOutbox(takes: false);

        $result = $this->makeRealPublisher($producer, $outbox)
            ->publish(new SomeEvent(new SomeModel(), ['foo' => 'bar'], 'Hello!'));

        self::assertCount(1, $outbox->stored);
        self::assertCount(1, $producer->sent);
        self::assertSame($result, $producer->sent[0]['message']->getMessageId());
    }

    public function testAfterCommitEventReachesTheOutboxOnlyAfterTheCommit(): void
    {
        // afterCommit is the developer's choice: the outbox doesn't change when the message is published.
        $deferredCallbacks = [];
        $transactionManager = new class($deferredCallbacks) {
            public function __construct(public array &$callbacks) {}
            public function addCallback(callable $callback): void {
                $this->callbacks[] = $callback;
            }
        };
        $outbox = new RecordingOutbox(takes: true);

        $publisher = $this->makeRealPublisher($producer, $outbox)
            ->setTransactionManagerResolver(fn() => $transactionManager);
        $publisher->publish(new SomeEventAfterCommit(new SomeModel(), ['foo' => 'bar'], 'Hello!'));

        self::assertCount(0, $outbox->stored);
        ($deferredCallbacks[0])();
        self::assertCount(1, $outbox->stored);
        self::assertCount(0, $producer->sent);
    }

    public function testMessagePublishedIsDispatchedWhenTheMessageIsSent(): void
    {
        [$events, $published] = $this->events();

        $result = $this->makeRealPublisher($producer, null, $events)
            ->publish(new SomeEvent(new SomeModel(), ['foo' => 'bar'], 'Hello!'));

        self::assertCount(1, $published->events);
        self::assertSame($result, $published->events[0]->message->id());
        self::assertSame('something.happened', $published->events[0]->message->event);
        self::assertSame($producer->sent[0]['message'], $published->events[0]->message->transportMessage());
    }

    public function testMessagePublishedIsNotDispatchedWhenSendingFails(): void
    {
        [$events, $published] = $this->events();
        $publisher = $this->makeRealPublisher($producer, null, $events);
        $producer->throwException(new \RuntimeException('Broker unavailable'));

        try {
            $publisher->publish(new SomeEvent(new SomeModel(), ['foo' => 'bar'], 'Hello!'));
            self::fail('The exception must reach the caller');
        } catch (\RuntimeException $e) {
            self::assertSame('Broker unavailable', $e->getMessage());
        }

        self::assertSame([], $published->events);
    }

    public function testNullOutboxTakesNothing(): void
    {
        $result = $this->makeRealPublisher($producer, new \RabbitEvents\Publisher\Outbox\NullOutbox())
            ->publish(new SomeEvent(new SomeModel(), ['foo' => 'bar'], 'Hello!'));

        self::assertSame($result, $producer->sent[0]['message']->getMessageId());
    }

    public function testServiceProviderGivesThePublisherTheBoundOutbox(): void
    {
        $app = new class extends \Illuminate\Container\Container {
            public function runningInConsole(): bool
            {
                return false;
            }
        };
        $registry = new \RabbitEvents\Foundation\Serialization\SerializerRegistry();
        $registry->register(new \RabbitEvents\Foundation\Serialization\JsonSerializer());
        $app->instance(\RabbitEvents\Foundation\Serialization\SerializerRegistry::class, $registry);
        $connection = new \RabbitEvents\Tests\Foundation\Stubs\ConnectionStub();
        $connection->producer = $producer = new \RabbitEvents\Tests\Foundation\Stubs\ProducerStub();
        $app->instance(\RabbitEvents\Foundation\Context::class, new \RabbitEvents\Foundation\Context($connection, $registry));
        // An outbox package binds its implementation; the provider must not override it.
        $app->singleton(\RabbitEvents\Publisher\Contracts\Outbox::class, fn() => new RecordingOutbox(takes: true));

        (new \RabbitEvents\Publisher\PublisherServiceProvider($app))->register();
        $app->make(Publisher::class)->publish(new SomeEvent(new SomeModel(), ['foo' => 'bar'], 'Hello!'));

        self::assertCount(1, $app->make(\RabbitEvents\Publisher\Contracts\Outbox::class)->stored);
        self::assertCount(0, $producer->sent);
    }
}

class RecordingOutbox implements \RabbitEvents\Publisher\Contracts\Outbox
{
    /** @var array<int, array{0: \RabbitEvents\Publisher\ShouldPublish, 1: Message}> */
    public array $stored = [];

    public function __construct(private bool $takes)
    {
    }

    public function store(\RabbitEvents\Publisher\ShouldPublish $event, Message $message): bool
    {
        $this->stored[] = [$event, $message];

        return $this->takes;
    }
}

class SomeEventAfterCommit extends SomeEvent implements \RabbitEvents\Publisher\ShouldPublishAfterCommit
{
}

class SomeEvent extends AbstractPublishableEvent
{
    /** @var SomeModel */
    private $model;
    /**
     * @var array
     */
    private $array;
    /**
     * @var string
     */
    private $string;

    public function __construct(SomeModel $model, array $array, string $string)
    {
        $this->model = $model;
        $this->array = $array;
        $this->string = $string;
    }

    /**
     * @return array
     */
    public function getArray(): array
    {
        return $this->array;
    }

    /**
     * @return string
     */
    public function getString(): string
    {
        return $this->string;
    }

    public function getModel()
    {
        return $this->model;
    }

    public function publishEventKey(): string
    {
        return 'something.happened';
    }

    public function toPublish(): array
    {
        return [
            $this->model->toArray(),
            $this->array,
            $this->string
        ];
    }
}

class SomeModel implements Arrayable
{

    /**
     * Get the instance as an array.
     *
     * @return array
     */
    public function toArray()
    {
        return [
            'item1' => 'value1',
            'item2' => 'value2'
        ];
    }
}
