<?php

namespace RabbitEvents\Tests\Listener\Message;

use RabbitEvents\Foundation\Message;
use RabbitEvents\Listener\Message\Handler;
use RabbitEvents\Listener\Support\Releaser;
use RabbitEvents\Tests\Foundation\Stubs\DestinationStub;
use RabbitEvents\Tests\Foundation\Stubs\ProducerStub;
use RabbitEvents\Tests\Listener\Payload;
use RabbitEvents\Tests\Listener\TestCase;

class HandlerTest extends TestCase
{
    private $handler;
    private $event = 'event.called';
    private $listenerClass = 'ListenerClass';

    public function setUp(): void
    {
        $callback = function ($event, $payload) {
            return "Publisher: $event. Item id: {$payload['id']}";
        };

        $this->handler = $this->getHandler($callback);
    }

    public function testHandle(): void
    {
        self::assertEquals("Publisher: $this->event. Item id: 1", $this->handler->handle());
    }

    public function testGetName(): void
    {
        $handler = new Handler(
            $this->getMessage(),
            static fn($event, $payload) => $event,
            $this->listenerClass,
            $this->releaser()
        );

        self::assertEquals("{$this->event}:{$this->listenerClass}", $handler->getName());
    }

    public function testExceptionFired(): void
    {
        $class = new class() {
            public function fire()
            {
                throw new \Exception("Exception in the `fire` method");
            }
        };

        $callback = function () use ($class) {
            return call_user_func_array([new $class, 'fire'], []);
        };

        $handler = $this->getHandler($callback);

        $this->expectExceptionMessage("Exception in the `fire` method");

        $handler->handle();
    }

    public function testFail(): void
    {
        $exception = new \Exception("Exception in the `fire` method");

        $this->expectExceptionMessage($exception->getMessage());

        $listener = new FailingListener();
        $message = $this->getMessage();
        $producer = new ProducerStub();

        // The deprecated $failedCallback (until 11.0); failed() through RegisteredListener: RetryPolicyTest.
        $handler = new Handler($message, static fn($event, $payload) => $event, FailingListener::class, $this->releaser($producer), [$listener, 'failed']);

        try {
            $handler->fail($exception);
        } finally {
            self::assertTrue($handler->hasFailed());
            self::assertTrue($message->hasFailed());
            self::assertEquals($message->payload->value(), $listener->payload);
            self::assertSame([], $producer->sent, 'A failed message is not released');
        }
    }

    public function testFailClosure(): void
    {
        $exception = new \Exception("Exception in `fire` method");

        $handler = $this->getHandler(static fn($event, $payload) => $event, \Closure::class);

        $handler->fail($exception);

        self::assertTrue($handler->hasFailed());
    }

    public function testRelease()
    {
        $handler = new Handler(
            new Message('some.event', new Payload([])),
            static fn($event, $payload) => $event,
            $this->listenerClass,
            $this->releaser($producer = new ProducerStub())
        );

        $handler->release(10);

        self::assertTrue($handler->isReleased());
        self::assertCount(1, $producer->sent);
        self::assertSame('some.event', $producer->sent[0]['message']->getProperty('event'));
        self::assertSame(10, $producer->deliveryDelay);
    }

    public function testAttemptsAreCountedPerListener()
    {
        $message = $this->getMessage()->increaseAttempts()->increaseAttempts();
        $releaser = $this->releaser();
        $first = new Handler($message, static fn() => null, 'App\\First@handle', $releaser);
        $second = new Handler($message, static fn() => null, 'App\\Second@handle', $releaser);

        self::assertSame(0, $first->attempts(), 'Deliveries of the message are not attempts of the listener');

        $first->countAttempt();
        $first->countAttempt();
        $second->countAttempt();

        self::assertSame(2, $first->attempts());
        self::assertSame(1, $second->attempts());
        self::assertSame([['App\\First@handle', 2], ['App\\Second@handle', 1]], $message->getProperty('x-listener-attempts'));
    }

    public function testClosureAttemptsAreDeliveriesOfTheMessage()
    {
        $message = $this->getMessage()->increaseAttempts()->increaseAttempts();
        $handler = new Handler($message, static fn() => null, \Closure::class, $this->releaser());

        $handler->countAttempt();

        self::assertSame(2, $handler->attempts());
        self::assertNull($message->getProperty('x-listener-attempts'));
    }

    private function releaser(?ProducerStub $producer = null): Releaser
    {
        return new Releaser(new DestinationStub('orders'), $producer ?? new ProducerStub());
    }

    protected function getMessage(): Message
    {
        return new Message($this->event, new Payload(['id' => 1]));
    }

    protected function getHandler(?callable $callback = null, ?string $listenerClass = null)
    {
        return new Handler(
            $this->getMessage(),
            $callback ?: static fn($event, $payload) => $event,
            $listenerClass ?: $this->listenerClass,
            $this->releaser()
        );
    }
}

class FailingListener
{
    public $payload;

    public function failed($payload, $exception)
    {
        $this->payload = $payload;

        throw $exception;
    }
}
