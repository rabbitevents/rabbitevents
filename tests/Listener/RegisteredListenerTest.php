<?php

declare(strict_types=1);

namespace RabbitEvents\Tests\Listener;

use PHPUnit\Framework\Attributes\DataProvider;
use RabbitEvents\Listener\Attributes\Backoff;
use RabbitEvents\Listener\Attributes\Tries;
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
            'attributes' => [AttributePolicyListenerStub::class, 1, 4, 2],
            'attribute backoff per attempt' => [AttributePolicyListenerStub::class, 3, 4, 6],
            'attribute backoff int' => [AttributeBackoffIntListenerStub::class, 1, null, 3],
            'attributes of a parent' => [InheritsAttributesListenerStub::class, 1, 4, 2],
            'attributes of a trait' => [TraitAttributesListenerStub::class, 2, 4, 4],
            'attributes of a trait of a parent' => [InheritsTraitAttributesListenerStub::class, 1, 4, 2],
            'attribute over the property of its class' => [AttributeAndPropertyListenerStub::class, 1, 4, 2],
            'property of a subclass over the attribute of its parent' => [OverridesAttributeListenerStub::class, 1, 6, 9],
            'method over attribute' => [AttributeAndMethodListenerStub::class, 1, 3, 7],
        ] + self::withLaravelAttributes([
            'Laravel attributes' => [LaravelAttributesListenerStub::class, 2, 4, 6],
            'Laravel attribute backoff int' => [LaravelBackoffIntListenerStub::class, 1, null, 3],
        ]);
    }

    /**
     * Laravel's queue attributes exist only in the illuminate/queue versions that provide them.
     */
    private static function withLaravelAttributes(array $cases): array
    {
        return class_exists(\Illuminate\Queue\Attributes\Tries::class) ? $cases : [];
    }

    #[DataProvider('policies')]
    public function testRetryPolicyIsReadFromTheClass(string $class, int $attempts, ?int $tries, ?int $backoff): void
    {
        $listener = (new Dispatcher())->makeListener($class)->forMessage();

        self::assertSame($tries, $listener->tries('order.created', ['id' => 1]));
        self::assertSame($backoff, $listener->backoff('order.created', ['id' => 1], $attempts));
    }

    public function testRetryPolicyIsReadWithoutTheConstructor(): void
    {
        // As Laravel reads the options of a queued listener: no dependencies are resolved to read them, and the
        // constructor can't change them.
        $listener = (new Dispatcher())->makeListener(ConstructorPolicyListenerStub::class)->forMessage();

        self::assertSame(2, $listener->tries('order.created', []));
        self::assertSame(4, $listener->backoff('order.created', [], 1));
        self::assertSame(0, ConstructorPolicyListenerStub::$instances);
    }

    public static function policyArguments(): array
    {
        return [
            'associative payload' => ['order.created', ['id' => 1], [['id' => 1]]],
            'list payload' => ['order.created', [1, 2], [1, 2]],
            'wildcard' => ['order.*', ['id' => 1], ['order.created', ['id' => 1]]],
        ];
    }

    #[DataProvider('policyArguments')]
    public function testPolicyMethodsGetTheArgumentsOfTheHandler(string $listen, array $payload, array $expected): void
    {
        $dispatcher = new Dispatcher();
        $dispatcher->listen($listen, ArgumentsPolicyListenerStub::class);
        [$listener] = $dispatcher->getListeners('order.created');
        $listener = $listener->forMessage();

        ArgumentsPolicyListenerStub::$received = [];
        $listener->tries('order.created', $payload);
        $listener->backoff('order.created', $payload, 1);
        $handled = $listener('order.created', $payload);

        self::assertSame([$expected, $expected], ArgumentsPolicyListenerStub::$received);
        self::assertSame($expected, $handled);
    }

    public function testPropertyOfAnObjectListenerSetAtRuntimeOverridesTheAttribute(): void
    {
        $object = new AttributeAndPropertyListenerStub();
        $object->tries = 8;

        self::assertSame(8, (new Dispatcher())->makeListener($object)->forMessage()->tries('e', []));
        self::assertSame(2, (new Dispatcher())->makeListener($object)->forMessage()->backoff('e', [], 1));
    }

    public function testObjectListenerPolicyIsReadFromTheObject(): void
    {
        $object = new PolicyPropertiesListenerStub();
        $object->tries = 8;

        self::assertSame(8, (new Dispatcher())->makeListener([$object, 'handle'])->forMessage()->tries('e', []));
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

    public function testListenerThatCannotBeBuiltKeepsItsPolicy(): void
    {
        // Its handling failed with the build exception, which must reach the worker, not one from failed().
        $listener = (new Dispatcher())->makeListener(UnbuildableListenerStub::class)->forMessage();

        self::assertSame(2, $listener->tries('order.created', []));
        self::assertSame(4, $listener->backoff('order.created', [], 1));
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
    public int $tries = 2;

    public int $backoff = 4;

    public function __construct(string $required)
    {
    }

    public function handle(): void
    {
    }
}

class ConstructorPolicyListenerStub
{
    public static int $instances = 0;

    public int $tries = 2;

    public int $backoff = 4;

    public function __construct()
    {
        self::$instances++;
        $this->tries = 9;
    }

    public function handle(): void
    {
    }
}

class ArgumentsPolicyListenerStub
{
    public static array $received = [];

    public function handle(mixed ...$arguments): array
    {
        return $arguments;
    }

    public function tries(mixed ...$arguments): int
    {
        self::$received[] = $arguments;

        return 1;
    }

    public function backoff(mixed ...$arguments): int
    {
        self::$received[] = $arguments;

        return 1;
    }
}

#[Tries(4)]
#[Backoff(2, 4, 6)]
class AttributePolicyListenerStub
{
    public function handle(): void
    {
    }
}

#[Backoff(3)]
class AttributeBackoffIntListenerStub
{
    public function handle(): void
    {
    }
}

class InheritsAttributesListenerStub extends AttributePolicyListenerStub
{
}

#[Tries(4)]
#[Backoff(2, 4, 6)]
trait RetryPolicyTraitStub
{
}

class TraitAttributesListenerStub
{
    use RetryPolicyTraitStub;

    public function handle(): void
    {
    }
}

class InheritsTraitAttributesListenerStub extends TraitAttributesListenerStub
{
}

#[Tries(4)]
#[Backoff(2)]
class AttributeAndPropertyListenerStub
{
    public int $tries = 6;

    public int $backoff = 9;

    public function handle(): void
    {
    }

    public function __invoke(): void
    {
    }
}

class OverridesAttributeListenerStub extends AttributePolicyListenerStub
{
    public int $tries = 6;

    public int $backoff = 9;
}

#[Tries(4)]
#[Backoff(2)]
class AttributeAndMethodListenerStub extends PolicyMethodsListenerStub
{
}

#[\Illuminate\Queue\Attributes\Tries(4)]
#[\Illuminate\Queue\Attributes\Backoff(2, 6)]
class LaravelAttributesListenerStub
{
    public function handle(): void
    {
    }
}

#[\Illuminate\Queue\Attributes\Backoff(3)]
class LaravelBackoffIntListenerStub
{
    public function handle(): void
    {
    }
}
