<?php

namespace RabbitEvents\Tests\Listener\Message;


use Mockery as m;
use RabbitEvents\Foundation\Contracts\Transport;
use RabbitEvents\Foundation\Message;
use RabbitEvents\Listener\Message\Handler;
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
            m::mock(\RabbitEvents\Listener\Support\Releaser::class)
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
        $transport = m::spy(\RabbitEvents\Listener\Support\Releaser::class);

        $handler = new Handler($message, static fn($event, $payload) => $event, FailingListener::class, $transport, static fn() => $listener);

        try {
            $handler->fail($exception);
        } finally {
            self::assertTrue($handler->hasFailed());
            self::assertTrue($message->hasFailed());
            self::assertEquals($message->payload->value(), $listener->payload);
            $transport->shouldNotHaveReceived('send');
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
            $transport = m::spy(\RabbitEvents\Listener\Support\Releaser::class)
        );

        $handler->release(10);

        $transport->shouldHaveReceived()->release(m::type(Message::class), 10);

        self::assertTrue($handler->isReleased());
    }

    public function testAttemptsAreCountedPerListener()
    {
        $message = $this->getMessage()->increaseAttempts()->increaseAttempts();
        $releaser = m::mock(\RabbitEvents\Listener\Support\Releaser::class);
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
        $handler = new Handler($message, static fn() => null, \Closure::class, m::mock(\RabbitEvents\Listener\Support\Releaser::class));

        $handler->countAttempt();

        self::assertSame(2, $handler->attempts());
        self::assertNull($message->getProperty('x-listener-attempts'));
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
            m::spy(\RabbitEvents\Listener\Support\Releaser::class)
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
