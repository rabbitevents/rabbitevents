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
     * @param \Closure|null $instance Gives the listener instance that handles the message (see Processor)
     */
    public function make(Message $message, callable $callback, string $listener, ?\Closure $instance = null): Handler
    {
        $instance ??= $this->lazyInstance(Str::before($listener, '@'));

        return new Handler(
            $message,
            \Closure::fromCallable($callback),
            $listener,
            $this->releaser,
            $this->failedCallback(Str::before($listener, '@'), $instance),
            $instance
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

    /**
     * The listener's failed() method, if it has one.
     */
    private function failedCallback(string $class, ?\Closure $instance): ?\Closure
    {
        // A container binding can only be checked by building it.
        if (!$instance || (class_exists($class) && !method_exists($class, 'failed'))) {
            return null;
        }

        return static function (...$arguments) use ($instance) {
            if (method_exists($listener = $instance(), 'failed')) {
                $listener->failed(...$arguments);
            }
        };
    }
}
