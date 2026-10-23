<?php

declare(strict_types=1);

namespace RabbitEvents\Tests\Listener;

use Illuminate\Container\Container;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RabbitEvents\Foundation\Message;
use RabbitEvents\Foundation\Support\JsonPayload;
use RabbitEvents\Listener\Dispatcher;
use RabbitEvents\Listener\Message\Handler;
use RabbitEvents\Listener\Support\Releaser;

/**
 * What listeners and their middleware receive, for every payload and kind of listener.
 *
 * A = the listener arguments: a non-empty list payload is the list of arguments (publish() wraps a payload
 * into one), anything else is the only argument.
 * - a regular listener gets ...A;
 * - a wildcard listener and a closure get ($event, $payload), like in Laravel (wildcards) and RabbitEvents 9.x;
 * - every middleware gets ...A, after $event for a wildcard listener; an onion middleware gets $next last;
 * - an onion middleware passing its arguments to $next doesn't change what the listener gets.
 */
class ListenerArgumentsTest extends TestCase
{
    protected function setUp(): void
    {
        ArgumentsRecorder::$calls = [];
    }

    public static function payloads(): array
    {
        return [
            // payload as delivered => listener arguments A
            'associative array (event class)' => [['id' => 1], [['id' => 1]]],
            'publish() helper' => [[['id' => 1]], [['id' => 1]]],
            'several arguments' => [[1, 2], [1, 2]],
            'empty array (publish() without data)' => [[], [[]]],
            'scalar' => ['text', ['text']],
            'null' => [null, [null]],
            'object' => [(object) ['id' => 1], [(object) ['id' => 1]]],
        ];
    }

    public static function cases(): array
    {
        $cases = [];

        foreach (self::payloads() as $name => [$payload, $arguments]) {
            foreach (['regular' => false, 'wildcard' => true] as $kind => $wildcard) {
                foreach (self::listeners() as $listenerName => $listener) {
                    $cases["{$kind} {$listenerName}, {$name}"] = [$listener, $wildcard || $listenerName === 'closure', $wildcard, $payload, $arguments];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function testListenerGetsItsArguments(\Closure $listener, bool $getsEventAndPayload, bool $wildcard, mixed $payload, array $arguments): void
    {
        $this->dispatch($listener(), $wildcard, $payload);

        $expected = $getsEventAndPayload ? ['order.created', is_array($payload) ? $payload : [$payload]] : $arguments;
        self::assertEquals([['listener', $expected]], ArgumentsRecorder::$calls);
    }

    #[DataProvider('payloadCases')]
    public function testMiddlewareGetTheListenerArguments(bool $wildcard, mixed $payload, array $arguments): void
    {
        $this->dispatch(MiddlewareRecordingListener::class, $wildcard, $payload);

        $middlewareArguments = $wildcard ? ['order.created', ...$arguments] : $arguments;
        $listenerArguments = $wildcard ? ['order.created', is_array($payload) ? $payload : [$payload]] : $arguments;

        self::assertEquals([
            ['filter', $middlewareArguments],
            ['onion', $middlewareArguments],
            ['listener', $listenerArguments],
        ], ArgumentsRecorder::$calls);
    }

    public static function payloadCases(): array
    {
        $cases = [];

        foreach (self::payloads() as $name => [$payload, $arguments]) {
            $cases["regular, {$name}"] = [false, $payload, $arguments];
            $cases["wildcard, {$name}"] = [true, $payload, $arguments];
        }

        return $cases;
    }

    /**
     * @return array<string, \Closure(): mixed> Listener definitions, built lazily (data providers run first)
     */
    private static function listeners(): array
    {
        return [
            'class' => static fn() => RecordingListener::class,
            'Class@method' => static fn() => RecordingListener::class . '@other',
            '[Class, method]' => static fn() => [RecordingListener::class, 'other'],
            '[$object, method]' => static fn() => [new RecordingListener(), 'other'],
            'invokable object' => static fn() => new RecordingListener(),
            'closure' => static fn() => static function (...$arguments) {
                ArgumentsRecorder::$calls[] = ['listener', $arguments];
            },
        ];
    }

    private function dispatch(mixed $listener, bool $wildcard, mixed $payload): void
    {
        $dispatcher = new Dispatcher(new Container());
        $dispatcher->listen($wildcard ? 'order.*' : 'order.created', $listener);

        foreach ($dispatcher->getListeners('order.created') as $prepared) {
            (new Handler(
                new Message('order.created', new JsonPayload($payload)),
                $prepared,
                'listener',
                m::mock(Releaser::class)
            ))->handle();
        }
    }
}

class ArgumentsRecorder
{
    public static array $calls = [];
}

class RecordingListener
{
    public function handle(...$arguments): void
    {
        ArgumentsRecorder::$calls[] = ['listener', $arguments];
    }

    public function other(...$arguments): void
    {
        ArgumentsRecorder::$calls[] = ['listener', $arguments];
    }

    public function __invoke(...$arguments): void
    {
        ArgumentsRecorder::$calls[] = ['listener', $arguments];
    }
}

class MiddlewareRecordingListener
{
    public array $middleware = [RecordingFilterMiddleware::class, RecordingOnionMiddleware::class];

    public function handle(...$arguments): void
    {
        ArgumentsRecorder::$calls[] = ['listener', $arguments];
    }
}

class RecordingFilterMiddleware
{
    public function handle(...$arguments): bool
    {
        ArgumentsRecorder::$calls[] = ['filter', $arguments];

        return true;
    }
}

class RecordingOnionMiddleware
{
    // Onion middleware are recognized by their last parameter, $next; func_get_args() keeps every argument.
    public function handle($payload = null, $next = null)
    {
        $arguments = func_get_args();
        $next = array_pop($arguments);
        ArgumentsRecorder::$calls[] = ['onion', $arguments];

        // Pass the payload on unchanged: without $event for a wildcard listener.
        return $next(...($arguments[0] === 'order.created' ? array_slice($arguments, 1) : $arguments));
    }
}
