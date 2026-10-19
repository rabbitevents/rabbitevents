<?php

namespace RabbitEvents\Tests\Listener\Message;

use Illuminate\Container\Container;
use Mockery as m;
use RabbitEvents\Foundation\Contracts\Transport;
use RabbitEvents\Foundation\Message;
use RabbitEvents\Listener\Message\Handler;
use RabbitEvents\Listener\Message\HandlerFactory;
use RabbitEvents\Tests\Listener\Payload;
use RabbitEvents\Tests\Listener\TestCase;

class HandlerFactoryTest extends TestCase
{
    public function testMake(): void
    {
        $message = new Message('item.created', new Payload([]));

        $container = m::mock(Container::class);
        $container->shouldReceive('make')->with('ClassName')->andReturn(new \stdClass());

        $factory = new HandlerFactory($container, m::mock(\RabbitEvents\Listener\Support\Releaser::class));
        $handler = $factory->make($message, static function() {}, 'ClassName');

        self::assertInstanceOf(Handler::class, $handler);

        self::assertSame($message, $handler->message);
    }
}
