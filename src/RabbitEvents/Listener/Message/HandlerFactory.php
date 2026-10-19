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

        if (
            $listenerClass !== \Closure::class
            && (!class_exists($listenerClass) || method_exists($listenerClass, 'failed'))
            && method_exists($listener = $this->container->make($listenerClass), 'failed')
        ) {
            $failedCallback = [$listener, 'failed'];
        }

        return new Handler(
            $message,
            $callback,
            $listenerClass,
            $this->releaser,
            $failedCallback
        );
    }
}
