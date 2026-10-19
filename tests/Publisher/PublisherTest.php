<?php

namespace RabbitEvents\Tests\Publisher;

use Illuminate\Contracts\Support\Arrayable;
use Mockery as m;
use RabbitEvents\Foundation\Contracts\Transport;
use RabbitEvents\Publisher\MessageFactory;
use RabbitEvents\Publisher\Publisher;
use RabbitEvents\Publisher\Support\AbstractPublishableEvent;
use RabbitEvents\Foundation\Message;

class PublisherTest extends TestCase
{
    private function makeRealPublisher(?\RabbitEvents\Tests\Foundation\Stubs\ProducerStub &$producer = null): Publisher
    {
        $registry = new \RabbitEvents\Foundation\Serialization\SerializerRegistry();
        $registry->register(new \RabbitEvents\Foundation\Serialization\JsonSerializer());

        $producer = new \RabbitEvents\Tests\Foundation\Stubs\ProducerStub();
        $sender = new \RabbitEvents\Foundation\Support\Sender(
            new \RabbitEvents\Tests\Foundation\Stubs\DestinationStub('events'),
            $producer
        );

        return new Publisher(new MessageFactory($registry), $sender);
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
