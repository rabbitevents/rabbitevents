<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\Message;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use RabbitEvents\Foundation\Message;
use RabbitEvents\Listener\Support\Releaser;

class HandlerFactory
{
    public function __construct(
        private Container $container,
        private Releaser $releaser
    ) {
    }

    /**
     * @param string $listener The listener as Class@method (or \Closure::class), used in logs and x-listener
     */
    public function make(Message $message, callable $callback, string $listener): Handler
    {
        return new Handler(
            $message,
            \Closure::fromCallable($callback),
            $listener,
            $this->releaser,
            $this->failedCallback(Str::before($listener, '@'))
        );
    }

    /**
     * The listener's failed() method, if it has one. Its instance is built only when a message fails.
     */
    private function failedCallback(string $class): ?\Closure
    {
        if ($class === \Closure::class) {
            return null;
        }

        if (class_exists($class)) {
            return method_exists($class, 'failed')
                ? fn(...$arguments) => $this->container->make($class)->failed(...$arguments)
                : null;
        }

        // A container binding: it can only be checked by building it, so build it once.
        if ($this->container->bound($class) && method_exists($listener = $this->container->make($class), 'failed')) {
            return $listener->failed(...);
        }

        return null;
    }
}
