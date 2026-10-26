<?php

declare(strict_types=1);

namespace RabbitEvents\Listener;

use Illuminate\Support\Str;

/**
 * A class or object listener as Dispatcher::getListeners() returns it. Closure listeners are returned as they are.
 *
 * The Dispatcher reuses these objects (Laravel caches wildcard listeners), so they build a new listener instance on
 * every call. The Processor takes forMessage(): its middleware, handler, retry policy and failed() share one instance.
 */
final class RegisteredListener
{
    /**
     * @var array{object, string}|null
     */
    private ?array $callback = null;

    public function __construct(
        private Dispatcher $dispatcher,
        private mixed $listener,
        private bool $wildcard,
        private bool $forMessage = false
    ) {
    }

    public function forMessage(): self
    {
        return new self($this->dispatcher, $this->listener, $this->wildcard, true);
    }

    /**
     * Identifies the listener as Class@method in `x-succeeded-listeners`, `x-listener-attempts`, logs and
     * `x-listener`, so that methods of one subscriber are told apart. It is stable across processes.
     */
    public function id(): string
    {
        if (is_array($this->listener)) {
            $target = $this->listener[0];

            return (is_object($target) ? $target::class : $target) . '@' . ($this->listener[1] ?? 'handle');
        }

        if (is_object($this->listener)) {
            return $this->listener::class . '@__invoke';
        }

        [$class, $method] = Str::parseCallback($this->listener, 'handle');

        return $class . '@' . $method;
    }

    /**
     * The listener object: built by the container for a class listener, the registered object otherwise.
     */
    public function instance(): object
    {
        return $this->callback()[0];
    }

    public function __invoke(string $event, mixed $payload): mixed
    {
        return $this->dispatcher->callListener($this->callback(), $this->wildcard, $event, $payload);
    }

    /**
     * @return array{object, string}
     */
    private function callback(): array
    {
        if (!$this->forMessage) {
            return $this->dispatcher->instantiateListener($this->listener);
        }

        return $this->callback ??= $this->dispatcher->instantiateListener($this->listener);
    }
}
