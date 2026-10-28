<?php

namespace RabbitEvents\Tests\Listener\Message;

use Illuminate\Container\Container;
use RabbitEvents\Foundation\Message;
use RabbitEvents\Listener\Dispatcher;
use RabbitEvents\Listener\Message\Handler;
use RabbitEvents\Listener\Message\HandlerFactory;
use RabbitEvents\Listener\Support\Releaser;
use RabbitEvents\Tests\Listener\Payload;
use RabbitEvents\Tests\Listener\TestCase;

class HandlerFactoryTest extends TestCase
{
    public function testMake(): void
    {
        $message = new Message('item.created', new Payload([]));

        $handler = $this->factory()->make($message, static function () {}, \Closure::class);

        self::assertInstanceOf(Handler::class, $handler);
        self::assertSame($message, $handler->message);
    }

    public function testAcceptsAnyCallable(): void
    {
        $handler = $this->factory()->make(new Message('e', new Payload([])), 'strtoupper', 'strtoupper');

        self::assertInstanceOf(Handler::class, $handler);
    }

    public function testFailedIsCalledOnTheInstanceThatHandledTheMessage(): void
    {
        $listener = (new Dispatcher())->makeListener(FailedListenerStub::class)->forMessage();
        $handler = $this->factory()->make(new Message('e', new Payload(['id' => 1])), $listener, FailedListenerStub::class . '@handle');

        $handler->handle();
        $handler->fail($exception = new \RuntimeException('boom'));

        self::assertSame([[['id' => 1], $exception]], $listener->instance()->failed);
    }

    private function factory(): HandlerFactory
    {
        return new HandlerFactory(new Container(), new \RabbitEvents\Listener\Support\Releaser(new \RabbitEvents\Tests\Foundation\Stubs\DestinationStub('orders'), new \RabbitEvents\Tests\Foundation\Stubs\ProducerStub()));
    }
}

class FailedListenerStub
{
    public array $failed = [];

    public function handle(array $payload): void
    {
    }

    public function failed(array $payload, \Throwable $exception): void
    {
        $this->failed[] = [$payload, $exception];
    }
}
