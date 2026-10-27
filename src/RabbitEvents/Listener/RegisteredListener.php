<?php

declare(strict_types=1);

namespace RabbitEvents\Listener;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use RabbitEvents\Listener\Attributes\Backoff;
use RabbitEvents\Listener\Attributes\Tries;
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
     * The attributes of each retry option: ours, and Laravel's (illuminate/queue) for listeners written the Laravel way.
     */
    private const ATTRIBUTES = [
        'tries' => [Tries::class, 'Illuminate\\Queue\\Attributes\\Tries'],
        'backoff' => [Backoff::class, 'Illuminate\\Queue\\Attributes\\Backoff'],
    ];

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
     * The listener object that handles the message: built by the container for a class listener and not kept in it, so no state leaks into
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
     * The listener's `tries()`, `#[Tries]` or `$tries`; null if it declares none (`--tries` applies).
     */
    public function tries(string $event, mixed $payload): ?int
    {
        $tries = $this->option('tries', $event, $payload);

        return is_null($tries) ? null : (int) $tries;
    }

    /**
     * Seconds before the next try, from the listener's `backoff()`, `#[Backoff]` or `$backoff`; null if it declares
     * none (`--sleep` applies). An array gives the delay after each attempt, its last value applies to the rest.
     */
    public function backoff(string $event, mixed $payload, int $attempts): ?int
    {
        $backoff = $this->option('backoff', $event, $payload);

        if (is_array($backoff)) {
            $backoff = array_values($backoff);

            return $backoff === [] ? null : (int) ($backoff[$attempts - 1] ?? end($backoff));
        }

        return is_null($backoff) ? null : (int) $backoff;
    }

    /**
     * Called on the instance that handled the message, so it sees the state handle() left. A listener that can't be
     * built has none: its handling failed with the same exception, which is reported.
     */
    public function failed(mixed $payload, Throwable $exception): void
    {
        try {
            $listener = $this->instance();
        } catch (Throwable) {
            return;
        }

        if (method_exists($listener, 'failed')) {
            $listener->failed($payload, $exception);
        }
    }

    /**
     * A retry option, in Laravel's order: the method (with the handler's arguments); the property, if an object
     * listener changed it; the attribute of the class, of a trait it uses or of a parent, unless a subclass
     * redeclares the property; the property.
     */
    private function option(string $name, string $event, mixed $payload): mixed
    {
        $listener = $this->policy();

        if (method_exists($listener, $name)) {
            return $listener->$name(...$this->dispatcher->handlerArguments($this->wildcard, $event, $payload));
        }

        $class = new \ReflectionClass($listener);
        $value = $listener->$name ?? null;

        if ($value !== ($class->getDefaultProperties()[$name] ?? null)) {
            return $value;
        }

        for ($declaring = $class; $declaring !== false; $declaring = $declaring->getParentClass()) {
            foreach ([$declaring, ...$declaring->getTraits()] as $source) {
                foreach (self::ATTRIBUTES[$name] as $attribute) {
                    // Laravel's attributes exist only where illuminate/queue provides them; the listener doesn't require it.
                    if (!class_exists($attribute) || !($attributes = $source->getAttributes($attribute))) {
                        continue;
                    }

                    $redeclared = $value !== null
                        && $class->getProperty($name)->getDeclaringClass()->isSubclassOf($declaring->getName());

                    return $redeclared ? $value : $attributes[0]->newInstance()->$name;
                }
            }
        }

        return $value;
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
     * The object the retry policy is read from. As Laravel reads the options of a queued listener, a class listener is
     * created without its constructor: no dependencies are resolved, and a listener that can't be built keeps its
     * policy. An object listener is read itself. A class that can't be created at all (missing, abstract) has none.
     */
    private function policy(): object
    {
        if (is_object($this->target)) {
            return $this->target;
        }

        try {
            return (new \ReflectionClass($this->target))->newInstanceWithoutConstructor();
        } catch (Throwable) {
            return new \stdClass();
        }
    }

    private function build(): object
    {
        return is_object($this->target) ? $this->target : $this->container->make($this->target);
    }
}
