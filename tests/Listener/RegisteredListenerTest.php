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

    public static function policies(): array
    {
        return [
            'none' => [RegisteredListenerStub::class, 1, null, null],
            'properties' => [PolicyPropertiesListenerStub::class, 1, 5, 10],
            'methods' => [PolicyMethodsListenerStub::class, 1, 3, 7],
            'backoff per attempt' => [PolicyPropertiesListenerStub::class, 2, 5, 20],
            'last backoff repeats' => [PolicyPropertiesListenerStub::class, 9, 5, 20],
            'empty backoff' => [EmptyBackoffListenerStub::class, 1, null, null],
        ];
    }

    #[DataProvider('policies')]
    public function testRetryPolicyIsReadFromTheInstance(string $class, int $attempts, ?int $tries, ?int $backoff): void
    {
        $listener = (new Dispatcher())->makeListener($class)->forMessage();

        self::assertSame($tries, $listener->tries());
        self::assertSame($backoff, $listener->backoff($attempts));
    }

    public function testFailedIsCalledOnTheInstance(): void
    {
        $listener = (new Dispatcher())->makeListener(PolicyMethodsListenerStub::class)->forMessage();
        $exception = new \RuntimeException('boom');

        $listener->failed(['id' => 1], $exception);

        self::assertSame([['id' => 1], $exception], $listener->instance()->failed);
    }

    public function testListenerWithoutFailedIsNotCalled(): void
    {
        $listener = (new Dispatcher())->makeListener(RegisteredListenerStub::class)->forMessage();

        $listener->failed(['id' => 1], new \RuntimeException('boom'));

        self::assertSame(1, RegisteredListenerStub::$instances);
    }

    public function testListenerThatCannotBeBuiltHasNoPolicy(): void
    {
        // Its handling failed with the build exception, which must reach the worker, not one from tries() or failed().
        $listener = (new Dispatcher())->makeListener(UnbuildableListenerStub::class)->forMessage();

        self::assertNull($listener->tries());
        self::assertNull($listener->backoff(1));
        $listener->failed([], new \RuntimeException('boom'));

        $this->expectException(\Illuminate\Contracts\Container\BindingResolutionException::class);
        $listener('order.created', []);
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

class PolicyPropertiesListenerStub
{
    public int $tries = 5;

    public array $backoff = [10, 20];

    public function handle(): void
    {
    }
}

class PolicyMethodsListenerStub
{
    public array $failed = [];

    public function handle(): void
    {
    }

    public function tries(): int
    {
        return 3;
    }

    public function backoff(): int
    {
        return 7;
    }

    public function failed(mixed $payload, \Throwable $exception): void
    {
        $this->failed = [$payload, $exception];
    }
}

class EmptyBackoffListenerStub
{
    public array $backoff = [];

    public function handle(): void
    {
    }
}

class UnbuildableListenerStub
{
    public function __construct(string $required)
    {
    }

    public function handle(): void
    {
    }
}
