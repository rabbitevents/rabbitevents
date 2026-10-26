<?php

declare(strict_types=1);

namespace RabbitEvents\Tests\Listener;

use PHPUnit\Framework\Attributes\DataProvider;
use RabbitEvents\Listener\Dispatcher;
use RabbitEvents\Listener\RegisteredListener;

class RegisteredListenerTest extends TestCase
{
    protected function setUp(): void
    {
        RegisteredListenerStub::$instances = 0;
    }

    public static function listeners(): array
    {
        $object = new RegisteredListenerStub();

        return [
            'class' => [RegisteredListenerStub::class, RegisteredListenerStub::class . '@handle'],
            'Class@method' => [RegisteredListenerStub::class . '@other', RegisteredListenerStub::class . '@other'],
            '[Class, method]' => [[RegisteredListenerStub::class, 'other'], RegisteredListenerStub::class . '@other'],
            '[Class]' => [[RegisteredListenerStub::class], RegisteredListenerStub::class . '@handle'],
            '[$object, method]' => [[$object, 'other'], RegisteredListenerStub::class . '@other'],
            'invokable object' => [$object, RegisteredListenerStub::class . '@__invoke'],
        ];
    }

    #[DataProvider('listeners')]
    public function testIdIsClassAtMethod(mixed $listener, string $id): void
    {
        self::assertSame($id, (new Dispatcher())->makeListener($listener)->id());
    }

    public function testClosureIsNotWrapped(): void
    {
        $closure = static fn() => null;

        self::assertSame($closure, (new Dispatcher())->makeListener($closure));
    }

    public function testListenerForMessageSharesOneInstance(): void
    {
        $listener = (new Dispatcher())->makeListener(RegisteredListenerStub::class)->forMessage();

        $handled = $listener('order.created', ['id' => 1]);

        self::assertSame($handled, $listener->instance());
        self::assertSame(1, RegisteredListenerStub::$instances);
    }

    public function testReusedListenerBuildsAnInstancePerCall(): void
    {
        // Laravel caches wildcard listeners: the object the Dispatcher returns must keep no instance.
        $dispatcher = new Dispatcher();
        $dispatcher->listen('order.*', RegisteredListenerStub::class);
        [$listener] = $dispatcher->getListeners('order.created');
        [$cached] = $dispatcher->getListeners('order.created');

        self::assertSame($listener, $cached);
        self::assertNotSame($listener('order.created', []), $listener('order.created', []));
        self::assertNotSame($listener->forMessage()->instance(), $listener->forMessage()->instance());
    }

    public function testObjectListenerIsItsOwnInstance(): void
    {
        $object = new RegisteredListenerStub();

        self::assertSame($object, (new Dispatcher())->makeListener([$object, 'other'])->forMessage()->instance());
        self::assertSame($object, (new Dispatcher())->makeListener($object)->forMessage()->instance());
        self::assertInstanceOf(RegisteredListener::class, (new Dispatcher())->makeListener($object));
    }
}

class RegisteredListenerStub
{
    public static int $instances = 0;

    public function __construct()
    {
        self::$instances++;
    }

    public function handle(mixed ...$arguments): self
    {
        return $this;
    }

    public function other(mixed ...$arguments): self
    {
        return $this;
    }

    public function __invoke(mixed ...$arguments): self
    {
        return $this;
    }
}
