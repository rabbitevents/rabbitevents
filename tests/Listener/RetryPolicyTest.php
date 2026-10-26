<?php

declare(strict_types=1);

namespace RabbitEvents\Tests\Listener;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher as EventsDispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use RabbitEvents\Foundation\Message;
use RabbitEvents\Listener\Dispatcher;
use RabbitEvents\Listener\Exceptions\FailedException;
use RabbitEvents\Listener\Exceptions\MaxAttemptsExceededException;
use RabbitEvents\Listener\ListenerOptions;
use RabbitEvents\Listener\Message\HandlerFactory;
use RabbitEvents\Listener\Message\Processor;
use RabbitEvents\Listener\Support\Releaser;
use RabbitEvents\Tests\Foundation\Stubs\DestinationStub;
use RabbitEvents\Tests\Foundation\Stubs\ProducerStub;

/**
 * `$tries`, `tries()`, `$backoff` and `backoff()` of a listener class override `--tries` and `--sleep`,
 * and every listener of a message counts its own attempts in `x-listener-attempts`.
 */
class RetryPolicyTest extends TestCase
{
    private Container $app;
    private Dispatcher $dispatcher;
    private ProducerStub $producer;
    private Processor $processor;

    protected function setUp(): void
    {
        $this->app = new Container();
        $this->dispatcher = new Dispatcher($this->app);
        $this->producer = new ProducerStub();
        $this->processor = new Processor(
            new HandlerFactory($this->app, new Releaser(new DestinationStub('orders'), $this->producer)),
            new EventsDispatcher($this->app),
            $this->dispatcher
        );
        RetryListenerCalls::$calls = [];
        CountsInstances::$fail = false;
        RemembersState::$failedWith = [];
    }

    /**
     * Every listener form that has a class, and the ways to declare tries.
     */
    public static function listenersWithFiveTries(): array
    {
        return [
            'class, $tries' => ['order.created', TriesProperty::class],
            'class, tries()' => ['order.created', TriesMethod::class],
            'Class@method' => ['order.created', TriesProperty::class . '@other'],
            '[Class, method]' => ['order.created', [TriesProperty::class, 'other']],
            '[$object, method]' => ['order.created', [new TriesProperty(), 'other']],
            'invokable object' => ['order.created', new InvokableTriesProperty()],
            'wildcard listener' => ['order.*', WildcardTriesProperty::class],
        ];
    }

    #[DataProvider('listenersWithFiveTries')]
    public function testListenerTriesOverrideWorkerTries(string $event, mixed $listener): void
    {
        $this->dispatcher->listen($event, $listener);
        $message = $this->message();

        for ($delivery = 1; $delivery <= 4; $delivery++) {
            $this->deliver($message, $this->options(maxTries: 1));
            self::assertFalse($message->hasFailed(), "Delivery $delivery must be released, not failed");
        }

        $this->deliver($message, $this->options(maxTries: 1));

        self::assertTrue($message->hasFailed());
        self::assertCount(5, RetryListenerCalls::$calls);
        self::assertCount(4, $this->producer->sent, 'Released after each of the first four tries');
    }

    public function testZeroTriesIsUnlimitedDespiteWorkerTries(): void
    {
        $this->dispatcher->listen('order.created', UnlimitedTries::class);
        $message = $this->message();

        for ($delivery = 1; $delivery <= 10; $delivery++) {
            $this->deliver($message, $this->options(maxTries: 3));
        }

        self::assertFalse($message->hasFailed());
        self::assertCount(10, $this->producer->sent);
    }

    public function testListenerWithoutTriesUsesWorkerTries(): void
    {
        $this->dispatcher->listen('order.created', NoPolicy::class);
        $message = $this->message();

        $this->deliver($message, $this->options(maxTries: 2, sleep: 7));
        self::assertFalse($message->hasFailed());
        self::assertSame(7, $this->producer->deliveryDelay);

        $this->deliver($message, $this->options(maxTries: 2, sleep: 7));
        self::assertTrue($message->hasFailed());
    }

    public static function backoffs(): array
    {
        return [
            '$backoff int' => [BackoffInt::class, [10, 10, 10]],
            '$backoff array, the last value repeats' => [BackoffArray::class, [1, 5, 5]],
            'backoff() method' => [BackoffMethod::class, [2, 4, 8]],
            'backoff() returning an int' => [BackoffMethodInt::class, [6, 6, 6]],
            'empty $backoff array uses --sleep' => [BackoffEmpty::class, [3, 3, 3]],
        ];
    }

    #[DataProvider('backoffs')]
    public function testBackoffDelaysEachRetry(string $listener, array $expectedDelays): void
    {
        $this->dispatcher->listen('order.created', $listener);
        $message = $this->message();
        $delays = [];

        foreach ($expectedDelays as $ignored) {
            $this->deliver($message, $this->options(maxTries: 0, sleep: 3));
            $delays[] = $this->producer->deliveryDelay;
        }

        self::assertSame($expectedDelays, $delays);
    }

    public function testEveryListenerCountsItsOwnAttempts(): void
    {
        // Each fails on its first try. The failure of the first one must not use up a try of the second.
        $this->dispatcher->listen('order.created', FirstFailsOnce::class);
        $this->dispatcher->listen('order.created', SecondFailsOnce::class);
        $message = $this->message();
        $options = $this->options(maxTries: 2);

        $this->deliver($message, $options); // First fails: try 1 of 2
        $this->deliver($message, $options); // First succeeds, Second fails: try 1 of 2 (delivery 2 of the message)
        self::assertFalse($message->hasFailed(), 'Second must get its own second try');
        $this->deliver($message, $options); // First is skipped, Second succeeds

        self::assertFalse($message->hasFailed());
        self::assertSame(['first', 'first', 'second', 'second'], RetryListenerCalls::$calls);
        self::assertSame(
            [[FirstFailsOnce::class . '@handle', 2], [SecondFailsOnce::class . '@handle', 2]],
            $message->getProperty('x-listener-attempts')
        );
    }

    public function testMethodsOfOneSubscriberCountAttemptsSeparately(): void
    {
        $this->dispatcher->listen('order.created', TriesProperty::class . '@succeed');
        $this->dispatcher->listen('order.created', TriesProperty::class . '@other');
        $message = $this->message();

        $this->deliver($message, $this->options(maxTries: 1));
        $this->deliver($message, $this->options(maxTries: 1));

        self::assertSame(
            [[TriesProperty::class . '@succeed', 1], [TriesProperty::class . '@other', 2]],
            $message->getProperty('x-listener-attempts')
        );
    }

    public function testListenerThatUsedUpItsTriesElsewhereFailsWithoutRunning(): void
    {
        // E.g. released by a worker whose listener allowed more tries before a deploy.
        $this->dispatcher->listen('order.created', TriesProperty::class);
        $message = $this->message();
        $message->setProperty('x-listener-attempts', [[TriesProperty::class . '@handle', 5]]);

        $exception = $this->deliver($message, $this->options(maxTries: 1));

        self::assertInstanceOf(MaxAttemptsExceededException::class, $exception);
        self::assertSame([], RetryListenerCalls::$calls);
        self::assertTrue($message->hasFailed());
        self::assertSame(TriesProperty::class . '@handle', $message->failedListener());
        // Only the attempts that ran are counted
        self::assertSame([[TriesProperty::class . '@handle', 5]], $message->getProperty('x-listener-attempts'));
    }

    public function testClosureCountsDeliveriesOfTheMessage(): void
    {
        $calls = 0;
        $this->dispatcher->listen('order.created', function () use (&$calls) {
            $calls++;
            throw new \RuntimeException('Closure boom');
        });
        $message = $this->message();

        $this->deliver($message, $this->options(maxTries: 2));
        self::assertFalse($message->hasFailed());
        $this->deliver($message, $this->options(maxTries: 2));
        self::assertTrue($message->hasFailed());

        // A closure that comes back with more deliveries than --tries runs once more and fails if it throws.
        $message = $this->message();
        $message->setProperty('x-attempts', 2);
        $calls = 0;
        $this->deliver($message, $this->options(maxTries: 2));

        self::assertSame(1, $calls);
        self::assertTrue($message->hasFailed());
        self::assertNull($message->getProperty('x-listener-attempts'));
    }

    public function testClosureThatUsesAListenerVariableIsAClosure(): void
    {
        $listener = TriesProperty::class;
        $arguments = null;
        $this->dispatcher->listen('order.created', function (...$passed) use ($listener, &$arguments) {
            $arguments = $passed;
            throw new \RuntimeException("$listener boom");
        });
        $message = $this->message();

        $this->deliver($message, $this->options(maxTries: 1));

        self::assertSame(['order.created', ['id' => 1]], $arguments);
        self::assertSame(\Closure::class, $message->failedListener());
        self::assertNull($message->getProperty('x-listener-attempts'));
    }

    public function testClosureRunsAfterTheRetriesOfAListenerBeforeIt(): void
    {
        // The deliveries a closure sees include the retries of the listeners before it: it is never failed
        // before it runs.
        $this->dispatcher->listen('order.created', SucceedsOnSecondAttempt::class);
        $calls = 0;
        $this->dispatcher->listen('order.created', function () use (&$calls) {
            $calls++;
        });
        $message = $this->message();

        self::assertInstanceOf(\RuntimeException::class, $this->deliver($message, $this->options(maxTries: 1)));
        self::assertNull($this->deliver($message, $this->options(maxTries: 1)));

        self::assertSame(1, $calls);
        self::assertFalse($message->hasFailed());
    }

    public static function regularAndWildcardEvent(): array
    {
        return [
            'regular' => ['order.created'],
            'wildcard (cached by the parent dispatcher)' => ['order.*'],
        ];
    }

    #[DataProvider('regularAndWildcardEvent')]
    public function testOneListenerInstancePerMessage(string $event): void
    {
        // The instance that handles the message also gives the retry policy: none is built for it.
        CountsInstances::$instances = 0;
        $this->dispatcher->listen($event, CountsInstances::class);

        $this->deliver($this->message(), $this->options(maxTries: 3));
        self::assertSame(1, CountsInstances::$instances);

        CountsInstances::$fail = true;
        $this->deliver($this->message(), $this->options(maxTries: 3));
        self::assertSame(2, CountsInstances::$instances);
        self::assertSame(1, $this->producer->deliveryDelay, 'backoff() of the listener applies');
    }

    public static function objectListeners(): array
    {
        return [
            '[$object, method]' => [fn(NeedsApiKey $listener) => [$listener, 'handle']],
            'invokable object' => [fn(NeedsApiKey $listener) => $listener],
        ];
    }

    /**
     * An object listener can't be built by the container: its own instance gives the policy and failed().
     */
    #[DataProvider('objectListeners')]
    public function testObjectListenerIsItsOwnPolicy(\Closure $form): void
    {
        $listener = new NeedsApiKey('secret');
        $listener->tries = 2;
        $this->dispatcher->listen('order.created', $form($listener));
        $message = $this->message();

        $exception = $this->deliver($message, $this->options(maxTries: 1, sleep: 60));
        self::assertSame('NeedsApiKey boom', $exception?->getMessage());
        self::assertFalse($message->hasFailed());
        self::assertSame(7, $this->producer->deliveryDelay);

        $this->deliver($message, $this->options(maxTries: 1, sleep: 60));
        self::assertTrue($message->hasFailed());
        self::assertSame(['secret'], $listener->failedWith);
    }

    public function testFailedIsCalledOnTheInstanceThatHandledTheMessage(): void
    {
        $this->dispatcher->listen('order.created', RemembersState::class);

        $this->deliver($this->message(), $this->options(maxTries: 1));

        self::assertSame(['handled order 1'], RemembersState::$failedWith);
    }

    public function testListenerThatCannotBeBuiltIsReleasedWithItsException(): void
    {
        $this->dispatcher->listen('order.created', CannotBeBuilt::class);
        $message = $this->message();

        $exception = $this->deliver($message, $this->options(maxTries: 2, sleep: 9));

        self::assertSame('Constructor boom', $exception?->getMessage());
        self::assertFalse($message->hasFailed());
        self::assertSame(9, $this->producer->deliveryDelay);

        $exception = $this->deliver($message, $this->options(maxTries: 2, sleep: 9));

        self::assertSame('Constructor boom', $exception?->getMessage());
        self::assertTrue($message->hasFailed());
    }

    public function testFailedExceptionFailsAtOnceDespiteTries(): void
    {
        $this->dispatcher->listen('order.created', FailsPermanently::class);
        $message = $this->message();

        $this->deliver($message, $this->options(maxTries: 1));

        self::assertTrue($message->hasFailed());
        self::assertSame([], $this->producer->sent);
    }

    private function deliver(Message $message, ListenerOptions $options): ?\Throwable
    {
        // As Consumer::nextMessage() does
        $message->increaseAttempts();

        try {
            $this->processor->process($message, $options);
        } catch (\Throwable $exception) {
            return $exception;
        }

        return null;
    }

    private function message(): Message
    {
        return new Message('order.created', new Payload(['id' => 1]), ['message_id' => 'msg-1']);
    }

    private function options(int $maxTries, int $sleep = 0): ListenerOptions
    {
        return new ListenerOptions('test-app', 'rabbitmq', ['order.created'], maxTries: $maxTries, sleep: $sleep);
    }
}

class RetryListenerCalls
{
    public static array $calls = [];

    public static function fail(string $listener): never
    {
        self::$calls[] = $listener;

        throw new \RuntimeException("$listener boom");
    }
}

class TriesProperty
{
    public int $tries = 5;

    public function handle(array $payload): void
    {
        RetryListenerCalls::fail(static::class);
    }

    public function other(array $payload): void
    {
        RetryListenerCalls::fail(static::class . '@other');
    }

    public function succeed(array $payload): void
    {
        RetryListenerCalls::$calls[] = static::class . '@succeed';
    }
}

class WildcardTriesProperty
{
    public int $tries = 5;

    public function handle(string $event, array $payload): void
    {
        RetryListenerCalls::fail(static::class);
    }
}

class TriesMethod
{
    public function tries(): int
    {
        return 5;
    }

    public function handle(array $payload): void
    {
        RetryListenerCalls::fail(static::class);
    }
}

class InvokableTriesProperty
{
    public int $tries = 5;

    public function __invoke(array $payload): void
    {
        RetryListenerCalls::fail(static::class);
    }
}

class NoPolicy
{
    public function handle(array $payload): void
    {
        RetryListenerCalls::fail(static::class);
    }
}

class BackoffInt extends NoPolicy
{
    public int $backoff = 10;
}

class BackoffArray extends NoPolicy
{
    public array $backoff = [1, 5];
}

class BackoffMethod extends NoPolicy
{
    public function backoff(): array
    {
        return [2, 4, 8];
    }
}

class BackoffMethodInt extends NoPolicy
{
    public function backoff(): int
    {
        return 6;
    }
}

class UnlimitedTries extends NoPolicy
{
    public int $tries = 0;
}

class BackoffEmpty extends NoPolicy
{
    public array $backoff = [];
}

class FirstFailsOnce
{
    public function handle(array $payload): void
    {
        RetryListenerCalls::$calls[] = 'first';

        if (count(array_keys(RetryListenerCalls::$calls, 'first')) === 1) {
            throw new \RuntimeException('First boom');
        }
    }
}

class SecondFailsOnce
{
    public function handle(array $payload): void
    {
        RetryListenerCalls::$calls[] = 'second';

        if (count(array_keys(RetryListenerCalls::$calls, 'second')) === 1) {
            throw new \RuntimeException('Second boom');
        }
    }
}

class CountsInstances
{
    public static int $instances = 0;
    public static bool $fail = false;
    public int $tries = 5;
    public int $backoff = 1;

    public function __construct()
    {
        self::$instances++;
    }

    public function handle(...$arguments): void
    {
        if (self::$fail) {
            throw new \RuntimeException('Counts boom');
        }
    }
}

class FailsPermanently
{
    public int $tries = 5;

    public function handle(array $payload): void
    {
        throw new FailedException('Invalid order');
    }
}

class SucceedsOnSecondAttempt
{
    public int $tries = 5;

    public function handle(array $payload): void
    {
        static $attempts = 0;

        if (++$attempts === 1) {
            throw new \RuntimeException('First attempt boom');
        }
    }
}

class NeedsApiKey
{
    public int $tries = 5;
    public int $backoff = 7;
    public array $failedWith = [];

    public function __construct(private string $apiKey)
    {
    }

    public function handle(array $payload): void
    {
        throw new \RuntimeException('NeedsApiKey boom');
    }

    public function __invoke(array $payload): void
    {
        $this->handle($payload);
    }

    public function failed(array $payload, \Throwable $exception): void
    {
        $this->failedWith[] = $this->apiKey;
    }
}

class RemembersState
{
    public static array $failedWith = [];
    private ?string $state = null;

    public function handle(array $payload): void
    {
        $this->state = 'handled order ' . $payload['id'];

        throw new \RuntimeException('RemembersState boom');
    }

    public function failed(array $payload, \Throwable $exception): void
    {
        self::$failedWith[] = $this->state;
    }
}

class CannotBeBuilt
{
    public function __construct()
    {
        throw new \RuntimeException('Constructor boom');
    }

    public function handle(array $payload): void
    {
    }

    public function failed(array $payload, \Throwable $exception): void
    {
    }
}
