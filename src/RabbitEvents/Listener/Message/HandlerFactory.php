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
            $callback,
            $listener,
            $this->releaser,
            is_object($callback) && method_exists($callback, 'instance')
                ? null
                : $this->lazyInstance(Str::before($listener, '@'))
        );
    }

    /**
     * Without the instance that handles the message, builds the listener class once, when the handler needs its
     * failed() or retry policy, and never keeps it in the container.
     */
    private function lazyInstance(string $class): ?\Closure
    {
        if ($class === \Closure::class || (!class_exists($class) && !$this->container->bound($class))) {
            return null;
        }

        $instance = null;

        return function () use ($class, &$instance): object {
            return $instance ??= $this->container->make($class);
        };
    }
}
