<?php

declare(strict_types=1);

namespace RabbitEvents\Listener;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use Throwable;

/**
 * A class or object listener as Dispatcher::getListeners() returns it. Closure listeners are returned as they are.
 * It is the only place that knows the forms a listener is registered in and the attributes it declares.
 *
 * The Dispatcher reuses these objects (Laravel caches wildcard listeners), so they build a new listener instance on
 * every call. The Processor takes forMessage(): its middleware, handler, retry policy and failed() share one instance.
 */
final class RegisteredListener
{
    /**
     * The listener's class, or the object registered as the listener.
     */
    private string|object $target;

    private string $method;

    private ?object $instance = null;

    public function __construct(
        private Dispatcher $dispatcher,
        private Container $container,
        mixed $listener,
        private bool $wildcard,
        private bool $forMessage = false
    ) {
        if (is_object($listener)) {
            [$this->target, $this->method] = [$listener, '__invoke'];
        } else {
            $listener = is_string($listener) ? Str::parseCallback($listener, 'handle') : $listener;
            [$this->target, $this->method] = [$listener[0], $listener[1] ?? 'handle'];
        }
    }

    public function forMessage(): self
    {
        $copy = clone $this;
        $copy->forMessage = true;
        $copy->instance = null;

        return $copy;
    }

    /**
     * Identifies the listener as Class@method in `x-succeeded-listeners`, `x-listener-attempts`, logs and
     * `x-listener`, so that methods of one subscriber are told apart. It is stable across processes.
     */
    public function id(): string
    {
        return (is_object($this->target) ? $this->target::class : $this->target) . '@' . $this->method;
    }

    /**
     * The listener object: built by the container for a class listener and not kept in it, so no state leaks into
     * the next message; the registered object otherwise.
     */
    public function instance(): object
    {
        if (!$this->forMessage) {
            return $this->build();
        }

        return $this->instance ??= $this->build();
    }

    public function __invoke(string $event, mixed $payload): mixed
    {
        $instance = $this->instance();
        // A class without the method is an invokable listener.
        $method = is_string($this->target) && !method_exists($instance, $this->method) ? '__invoke' : $this->method;

        return $this->dispatcher->callListener(
            [$instance, $method],
            $this->middleware($instance),
            $this->wildcard,
            $event,
            $payload
        );
    }

    /**
     * The listener's `tries()` or `$tries`; null if it declares none (`--tries` applies).
     */
    public function tries(): ?int
    {
        $listener = $this->policyHolder();

        if (method_exists($listener, 'tries')) {
            return (int) $listener->tries();
        }

        return isset($listener->tries) ? (int) $listener->tries : null;
    }

    /**
     * Seconds before the next try, from the listener's `backoff()` or `$backoff`; null if it declares none
     * (`--sleep` applies). An array gives the delay after each attempt, its last value applies to the rest.
     */
    public function backoff(int $attempts): ?int
    {
        $listener = $this->policyHolder();

        $backoff = method_exists($listener, 'backoff') ? $listener->backoff() : ($listener->backoff ?? null);

        if (is_array($backoff)) {
            $backoff = array_values($backoff);

            return $backoff === [] ? null : (int) ($backoff[$attempts - 1] ?? end($backoff));
        }

        return is_null($backoff) ? null : (int) $backoff;
    }

    public function failed(mixed $payload, Throwable $exception): void
    {
        $listener = $this->policyHolder();

        if (method_exists($listener, 'failed')) {
            $listener->failed($payload, $exception);
        }
    }

    /**
     * The listener's `$middleware` and its `middleware()` method, in this order.
     */
    private function middleware(object $instance): array
    {
        $middleware = isset($instance->middleware) ? (array) $instance->middleware : [];

        if (method_exists($instance, 'middleware')) {
            $middleware[] = [$instance, 'middleware'];
        }

        return $middleware;
    }

    /**
     * The instance that declares the retry policy and failed(). A listener that can't be built has none: its
     * handling failed with the same exception, which is reported.
     */
    private function policyHolder(): object
    {
        try {
            return $this->instance();
        } catch (Throwable) {
            return new \stdClass();
        }
    }

    private function build(): object
    {
        return is_object($this->target) ? $this->target : $this->container->make($this->target);
    }
}
