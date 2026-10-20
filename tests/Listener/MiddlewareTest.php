<?php

namespace RabbitEvents\Tests\Listener;


use RabbitEvents\Foundation\Contracts\Transport;
use RabbitEvents\Foundation\Message;
use RabbitEvents\Listener\Dispatcher;
use RabbitEvents\Listener\Message\Handler;
use RabbitEvents\Tests\Listener\Stubs\ListenerStubForMiddleware;
use RabbitEvents\Tests\Listener\Stubs\ListenerWithAttributeMiddleware;
use RabbitEvents\Tests\Listener\Stubs\ListenerWithMethodMiddleware;
use RabbitEvents\Tests\Listener\Stubs\ListenerWithMixOfMiddleware;

class MiddlewareTest extends TestCase
{
    public function testExecuteMiddlewareAsMethod(): void
    {
        $message = $this->makeMessage(true);

        self::assertEquals(1, $this->makeHandler($message, $this->makeCallback(ListenerWithMethodMiddleware::class))->handle());

        // Wildcard
        self::assertEquals(1, $this->makeHandler($message, $this->makeCallback(ListenerWithMethodMiddleware::class, true))->handle());
    }

    public function testMiddlewareReturnedFalse(): void
    {
        $message = $this->makeMessage(false);

        self::assertNull($this->makeHandler($message, $this->makeCallback(ListenerWithMethodMiddleware::class))->handle());

        // Wildcard
        self::assertNull($this->makeHandler($message, $this->makeCallback(ListenerWithMethodMiddleware::class, true))->handle());
    }

    public function testExecuteMiddlewareAsArgument(): void
    {
        $message = $this->makeMessage(true);

        self::assertEquals(2, $this->makeHandler($message, $this->makeCallback(ListenerWithAttributeMiddleware::class))->handle());

        // Wildcard
        self::assertEquals(2, $this->makeHandler($message, $this->makeCallback(ListenerWithAttributeMiddleware::class, true))->handle());
    }

    public function testMiddlewareAsArgReturnedFalse(): void
    {
        $message = $this->makeMessage(false);

        self::assertNull($this->makeHandler($message, $this->makeCallback(ListenerWithAttributeMiddleware::class))->handle());

        // Wildcard
        self::assertNull($this->makeHandler($message, $this->makeCallback(ListenerWithAttributeMiddleware::class, true))->handle());
    }

    public function testAllKindOfMiddlewareTogether(): void
    {
         $message = $this->makeMessage(true);

        self::assertEquals(3, $this->makeHandler($message, $this->makeCallback(ListenerWithMixOfMiddleware::class))->handle());

        // Wildcard
        self::assertEquals(3, $this->makeHandler($message, $this->makeCallback(ListenerWithMixOfMiddleware::class, true))->handle());
    }

    public function testAllKindOfMiddlewareTogetherReturnedFalse(): void
    {
        $message = $this->makeMessage(false);

        self::assertNull($this->makeHandler($message, $this->makeCallback(ListenerWithMixOfMiddleware::class))->handle());

        // Wildcard
        self::assertNull($this->makeHandler($message, $this->makeCallback(ListenerWithMixOfMiddleware::class, true))->handle());
    }

    /**
     * Test whether middleware receive correct array structure
     */
    public function testMiddlewareReceiveAssociativeArray(): void
    {
        $payload = ['first' => '1'];
        //incomming message payload should always be wrapped in array
        $message = $this->makeMessage([$payload]);

        self::assertEquals([$payload], $this->makeHandler($message, $this->makeCallback(ListenerStubForMiddleware::class))->handle());

        // Wildcard
        self::assertEquals(['event', $payload], $this->makeHandler($message, $this->makeCallback(ListenerStubForMiddleware::class, true))->handle());
    }

    public function testExecutePipelineMiddlewareWithNextClosure(): void
    {
        $pipelineMiddleware = new class {
            public bool $beforeCalled = false;
            public bool $afterCalled = false;

            public function handle($payload, \Closure $next)
            {
                $this->beforeCalled = true;
                $result = $next($payload);
                $this->afterCalled = true;
                return "wrapped:{$result}";
            }
        };

        $listener = new class ($pipelineMiddleware) {
            public array $middleware = [];
            public bool $handled = false;

            public function __construct($middleware)
            {
                $this->middleware = [$middleware];
            }

            public function handle($payload): string
            {
                $this->handled = true;
                return "result:{$payload}";
            }
        };

        $dispatcher = new Dispatcher();
        $callback = $dispatcher->makeListener([$listener, 'handle']);

        $message = $this->makeMessage('data');
        $handler = $this->makeHandler($message, $callback);

        $result = $handler->handle();

        self::assertEquals('wrapped:result:data', $result);
        self::assertTrue($pipelineMiddleware->beforeCalled);
        self::assertTrue($pipelineMiddleware->afterCalled);
    }

    public function testExecutePipelineMiddlewareWithNullableClosure(): void
    {
        $pipelineMiddleware = new class {
            public bool $called = false;

            public function handle($payload, ?\Closure $next = null)
            {
                $this->called = true;
                return $next ? 'nullable:' . $next($payload) : 'no-next';
            }
        };

        $listener = new class ($pipelineMiddleware) {
            public array $middleware = [];

            public function __construct($middleware)
            {
                $this->middleware = [$middleware];
            }

            public function handle($payload): string
            {
                return "ok:{$payload}";
            }
        };

        $dispatcher = new Dispatcher();
        $callback = $dispatcher->makeListener([$listener, 'handle']);
        $handler = $this->makeHandler($this->makeMessage('val'), $callback);

        self::assertEquals('nullable:ok:val', $handler->handle());
        self::assertTrue($pipelineMiddleware->called);
    }

    public function testMiddlewareResolutionOrderPrefersMiddlewareMethodOverHandleAndInvoke(): void
    {
        $multiMethodMiddleware = new class {
            public string $calledMethod = '';

            public function middleware($payload)
            {
                $this->calledMethod = 'middleware';
                return true;
            }

            public function handle($payload)
            {
                $this->calledMethod = 'handle';
                return true;
            }

            public function __invoke($payload)
            {
                $this->calledMethod = '__invoke';
                return true;
            }
        };

        $listener = new class ($multiMethodMiddleware) {
            public array $middleware = [];

            public function __construct($middleware)
            {
                $this->middleware = [$middleware];
            }

            public function handle($payload): string
            {
                return "done:{$payload}";
            }
        };

        $dispatcher = new Dispatcher();
        $callback = $dispatcher->makeListener([$listener, 'handle']);
        $handler = $this->makeHandler($this->makeMessage('x'), $callback);

        self::assertEquals('done:x', $handler->handle());
        self::assertEquals('middleware', $multiMethodMiddleware->calledMethod);
    }

    public function testInvokableObjectAsMiddleware(): void
    {
        $invokableMiddleware = new class {
            public bool $called = false;

            public function __invoke($payload)
            {
                $this->called = true;
                return true;
            }
        };

        $listener = new class ($invokableMiddleware) {
            public array $middleware = [];

            public function __construct($middleware)
            {
                $this->middleware = [$middleware];
            }

            public function handle($payload): string
            {
                return "invoked:{$payload}";
            }
        };

        $dispatcher = new Dispatcher();
        $callback = $dispatcher->makeListener([$listener, 'handle']);
        $handler = $this->makeHandler($this->makeMessage('test'), $callback);

        self::assertEquals('invoked:test', $handler->handle());
        self::assertTrue($invokableMiddleware->called);
    }

    public function testInvokableObjectWithHandleMethodPrefersInvokeLike9x(): void
    {
        $invokableWithHandle = new class {
            public string $calledMethod = '';

            public function __invoke($payload)
            {
                $this->calledMethod = '__invoke';
                return true;
            }

            public function handle($payload)
            {
                $this->calledMethod = 'handle';
                return true;
            }
        };

        $listener = new class ($invokableWithHandle) {
            public array $middleware = [];

            public function __construct($middleware)
            {
                $this->middleware = [$middleware];
            }

            public function handle($payload): string
            {
                return "ok:{$payload}";
            }
        };

        $dispatcher = new Dispatcher();
        $callback = $dispatcher->makeListener([$listener, 'handle']);
        $handler = $this->makeHandler($this->makeMessage('test'), $callback);

        self::assertEquals('ok:test', $handler->handle());
        self::assertEquals('__invoke', $invokableWithHandle->calledMethod);
    }

    public function testOnionMiddlewareReceivesThePayloadLikeTheListener(): void
    {
        $middleware = new class {
            public mixed $received = null;

            public function handle($payload, \Closure $next)
            {
                $this->received = $payload;

                return $next(['user_id' => $payload['user_id'] + 1]);
            }
        };
        $listener = new class ($middleware) {
            public array $middleware;
            public function __construct($middleware) { $this->middleware = [$middleware]; }
            public function handle(array $payload): array { return $payload; }
        };

        $handler = $this->makeHandler($this->makeMessage(['user_id' => 1]), $this->makeCallback([$listener, 'handle']));

        self::assertSame(['user_id' => 2], $handler->handle());
        self::assertSame(['user_id' => 1], $middleware->received);
    }

    public function testWildcardFilterMiddlewareReceivesEventAndPayload(): void
    {
        $middleware = new class {
            public array $received = [];

            public function handle(...$args): bool
            {
                $this->received = $args;

                return true;
            }
        };
        $listener = new class ($middleware) {
            public array $middleware;
            public function __construct($middleware) { $this->middleware = [$middleware]; }
            public function handle(string $event, array $payload): array { return $payload; }
        };

        $handler = $this->makeHandler($this->makeMessage(['user_id' => 1, 'email' => 'a@b.c']), $this->makeCallback([$listener, 'handle'], true));

        self::assertSame(['user_id' => 1, 'email' => 'a@b.c'], $handler->handle());
        self::assertSame(['event', ['user_id' => 1, 'email' => 'a@b.c']], $middleware->received);
    }

    public function testListenerClassIsInstantiatedForEveryMessage(): void
    {
        $callback = $this->makeCallback(StatefulListenerStub::class);

        $first = $this->makeHandler($this->makeMessage(['n' => 1]), $callback)->handle();
        $second = $this->makeHandler($this->makeMessage(['n' => 2]), $callback)->handle();

        // State of the first message must not leak into the second one.
        self::assertSame([1], $first);
        self::assertSame([2], $second);
    }

    private function makeCallback($listenerClass, $wildcard = false): callable
    {
        return (new Dispatcher())->makeListener($listenerClass, $wildcard);
    }

    private function makeMessage(mixed $payload): Message
    {
        return new Message('event', new Payload($payload));
    }

    private function makeHandler($message, $callback): Handler
    {
        return new Handler($message, $callback, __CLASS__, \Mockery::mock(\RabbitEvents\Listener\Support\Releaser::class));
    }
}

class StatefulListenerStub
{
    private array $seen = [];

    public function handle(array $payload): array
    {
        $this->seen[] = $payload['n'];

        return $this->seen;
    }
}
