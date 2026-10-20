<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\Message;

use Illuminate\Contracts\Container\Container;
use RabbitEvents\Foundation\Message;
use RabbitEvents\Listener\Support\Releaser;

class HandlerFactory
{
    public function __construct(
        private Container $container,
        private Releaser $releaser
    ) {
    }

    public function make(Message $message, callable $callback, string $listenerClass): Handler
    {
        $failedCallback = null;

        if ($this->hasFailedMethod($listenerClass)) {
            $failedCallback = [$this->container->make($listenerClass), 'failed'];
        }

        return new Handler(
            $message,
            \Closure::fromCallable($callback),
            $listenerClass,
            $this->releaser,
            $failedCallback
        );
    }

    /**
     * Only a listener class (or a container binding of one) can have failed(); don't build anything else.
     */
    private function hasFailedMethod(string $listenerClass): bool
    {
        if ($listenerClass === \Closure::class) {
            return false;
        }

        if (class_exists($listenerClass)) {
            return method_exists($listenerClass, 'failed');
        }

        return $this->container->bound($listenerClass)
            && method_exists($this->container->make($listenerClass), 'failed');
    }
}
