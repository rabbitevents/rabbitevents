<?php

declare(strict_types=1);

namespace RabbitEvents\Listener;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Events\Dispatcher as BaseDispatcher;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class Dispatcher extends BaseDispatcher
{
    public function __construct(?ContainerContract $container = null)
    {
        parent::__construct($container ?? new Container());
    }

    /**
     * @return array
     */
    public function getEvents(): array
    {
        return array_merge(array_keys($this->listeners), array_keys($this->wildcards));
    }

    /**
     * Register an event listener with the dispatcher.
     *
     * @param string|array $events
     * @param mixed $listener
     * @return void
     */
    public function listen($events, $listener = null): void
    {
        foreach ((array) $events as $event) {
            if (str_contains($event, '*')) {
                $this->setupWildcardListen($event, $listener);
            } else {
                // Check for duplicates before adding
                if (!$this->isDuplicateListener($event, $listener)) {
                    $this->listeners[$event][] = $listener;
                }
            }
        }
    }

    /**
     * Check if a listener is already registered for an event.
     *
     * @param string $event
     * @param mixed $listener
     * @return bool
     */
    protected function isDuplicateListener(string $event, $listener): bool
    {
        if (!isset($this->listeners[$event])) {
            return false;
        }

        $normalizedListener = static::listenerKey($listener);

        foreach ($this->listeners[$event] as $existingListener) {
            if ($normalizedListener === static::listenerKey($existingListener)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A key that is equal for listener definitions that call the same thing:
     * "Class", "Class@handle" and [Class, 'handle'] are one listener.
     */
    public static function listenerKey(mixed $listener): string
    {
        if (is_array($listener)) {
            $target = is_object($listener[0]) ? spl_object_hash($listener[0]) : $listener[0];

            return $target . '@' . ($listener[1] ?? 'handle');
        }

        if (is_string($listener)) {
            // Strip '::class' suffix if present
            $listener = str_replace('::class', '', $listener);

            return str_contains($listener, '@') ? $listener : $listener . '@handle';
        }

        if (is_object($listener)) {
            return spl_object_hash($listener);
        }

        return serialize($listener);
    }

    /**
     * @inheritdoc
     */
    public function makeListener($listener, $wildcard = false): callable
    {
        // A closure is called as Handler calls it, with ($event, $payload), as in every released version.
        if ($listener instanceof Closure) {
            return $listener;
        }

        $invoke = function (array $listener, $event, $payload) use ($wildcard) {
            $arguments = static::listenerArguments($payload);
            $call = static function (array $passed) use ($listener, $wildcard, $event, $payload, $arguments) {
                if (!$wildcard) {
                    return $listener(...array_values($passed));
                }

                // A wildcard listener gets ($event, $payload) like in Laravel and 9.x; if an onion middleware
                // passed something else to $next, it gets that.
                $passedPayload = $passed === $arguments ? $payload : static::payloadFromArguments($passed);

                return $listener($event, $passedPayload);
            };

            if (empty($throughMiddleware = $this->extractMiddleware($listener[0]))) {
                return $call($arguments);
            }

            return (new Pipeline($this->container))
                ->send($arguments)
                ->through(array_map(
                    fn ($middleware) => $this->wrapMiddlewareIntoPipelineStage($middleware, $event, $wildcard),
                    $throughMiddleware
                ))
                ->then($call);
        };

        // Cloned in Handler for every message, so its middleware, handle(), retry policy and failed() share one
        // instance without leaking it across messages (wildcard listeners are cached by the parent dispatcher).
        return new class ($this, $listener, $invoke) {
            private ?array $instantiated = null;

            public function __construct(
                private Dispatcher $dispatcher,
                public readonly mixed $listener,
                private Closure $invoke
            ) {
            }

            public function __invoke(mixed $event, mixed $payload): mixed
            {
                return ($this->invoke)($this->instantiate(), $event, $payload);
            }

            public function instance(): object
            {
                return $this->instantiate()[0];
            }

            public function __clone(): void
            {
                $this->instantiated = null;
            }

            /**
             * @return array{object, string}
             */
            private function instantiate(): array
            {
                return $this->instantiated ??= $this->dispatcher->instantiateListener($this->listener);
            }
        };
    }

    /**
     * A non-empty list payload is the list of the listener's arguments (publish() wraps a payload into one);
     * any other payload (an associative or empty array, a scalar, null, an object) is the only argument.
     */
    protected static function listenerArguments(mixed $payload): array
    {
        return static::isArgumentList($payload) ? $payload : [$payload];
    }

    /**
     * The payload of the listener arguments an onion middleware passed to $next.
     */
    protected static function payloadFromArguments(array $arguments): mixed
    {
        return count($arguments) === 1 && !static::isArgumentList($arguments[0]) ? $arguments[0] : $arguments;
    }

    private static function isArgumentList(mixed $payload): bool
    {
        return is_array($payload) && $payload !== [] && !Arr::isAssoc($payload);
    }

    protected static array $middlewareExpectsNextCache = [];

    /**
     * @var \WeakMap<object, bool>|null
     */
    protected static ?\WeakMap $objectMiddlewareExpectsNextCache = null;

    protected function wrapMiddlewareIntoPipelineStage(callable $middleware, string $event, bool $wildcard): Closure
    {
        $expectsNext = $this->middlewareExpectsNext($middleware);

        // $passable is the list of the listener arguments. Every middleware gets them, after $event for a
        // wildcard listener; an onion middleware gets $next last and passes the arguments on to it.
        return function (array $passable, $next) use ($middleware, $event, $wildcard, $expectsNext) {
            $arguments = $wildcard ? [$event, ...$passable] : $passable;

            if ($expectsNext) {
                return ($middleware)(...[...$arguments, static fn(...$passed) => $next($passed)]);
            }

            $result = ($middleware)(...$arguments);

            if (false === $result) {
                return null;
            }

            return $next($passable);
        };
    }

    protected function middlewareExpectsNext(callable $middleware): bool
    {
        $key = null;

        if (is_array($middleware)) {
            $key = (is_object($middleware[0]) ? get_class($middleware[0]) : $middleware[0]) . '@' . $middleware[1];
            if (isset(static::$middlewareExpectsNextCache[$key])) {
                return static::$middlewareExpectsNextCache[$key];
            }
        } elseif ($middleware instanceof Closure) {
            static::$objectMiddlewareExpectsNextCache ??= new \WeakMap();
            if (isset(static::$objectMiddlewareExpectsNextCache[$middleware])) {
                return static::$objectMiddlewareExpectsNextCache[$middleware];
            }
        } elseif (is_object($middleware)) {
            $key = get_class($middleware) . '@__invoke';
            if (isset(static::$middlewareExpectsNextCache[$key])) {
                return static::$middlewareExpectsNextCache[$key];
            }
        } else {
            $key = (string) $middleware;
            if (isset(static::$middlewareExpectsNextCache[$key])) {
                return static::$middlewareExpectsNextCache[$key];
            }
        }

        $reflector = is_array($middleware)
            ? new \ReflectionMethod($middleware[0], $middleware[1])
            : new \ReflectionFunction(\Closure::fromCallable($middleware));

        $parameters = $reflector->getParameters();
        $lastParam = !empty($parameters) ? end($parameters) : null;

        $expectsNext = false;
        if ($lastParam) {
            if ($lastParam->getName() === 'next') {
                $expectsNext = true;
            } elseif ($lastParam->hasType()) {
                $type = $lastParam->getType();
                $types = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];
                foreach ($types as $namedType) {
                    if ($namedType instanceof \ReflectionNamedType) {
                        $typeName = ltrim($namedType->getName(), '\\');
                        if (in_array($typeName, ['Closure', 'callable'], true)) {
                            $expectsNext = true;
                            break;
                        }
                    }
                }
            }
        }

        if ($middleware instanceof Closure) {
            return static::$objectMiddlewareExpectsNextCache[$middleware] = $expectsNext;
        }

        return static::$middlewareExpectsNextCache[$key] = $expectsNext;
    }

    /**
     * Turns a listener into [instance, method]: one instance per message for a class listener, shared by its
     * middleware, its handler, its retry policy and its failed(), and not kept in the container, so no state
     * leaks into the next message.
     *
     * @return array{object, string}
     */
    public function instantiateListener(mixed $listener): array
    {
        if (is_string($listener)) {
            $listener = Str::parseCallback($listener, 'handle');
        }

        if (is_object($listener)) {
            return [$listener, '__invoke'];
        }

        $instance = is_object($listener[0]) ? $listener[0] : $this->container->make($listener[0]);
        $method = $listener[1] ?? 'handle';

        return [$instance, method_exists($instance, $method) ? $method : '__invoke'];
    }

    protected function extractMiddleware(object $instance): array
    {
        $result = [];

        if (isset($instance->middleware)) {
            foreach ((array) $instance->middleware as $middleware) {
                $result[] = $this->createMiddlewareCallable($middleware);
            }
        }

        if (method_exists($instance, 'middleware')) {
            $result[] = [$instance, 'middleware'];
        }

        return $result;
    }

    protected function createMiddlewareCallable($mixed): callable
    {
        if (is_object($mixed) && method_exists($mixed, 'middleware')) {
            return [$mixed, 'middleware'];
        }

        if (is_callable($mixed)) {
            return $mixed;
        }

        if (is_object($mixed) && method_exists($mixed, 'handle')) {
            return [$mixed, 'handle'];
        }

        if (is_string($mixed)) {
            return $this->createClassCallable($mixed);
        }

        throw new \RuntimeException('Invalid middleware definition');
    }

    protected function handlerShouldBeQueued($class): bool
    {
        return false;
    }
}
