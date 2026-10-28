<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\Message;

use Illuminate\Contracts\Container\Container;
use RabbitEvents\Foundation\Message;
use RabbitEvents\Listener\Support\Releaser;

class HandlerFactory
{
    /**
     * @param Container $container Deprecated, not used: listeners are built by the Dispatcher (see RegisteredListener).
     *                             It will be removed in 11.0.
     */
    public function __construct(
        Container $container,
        private Releaser $releaser
    ) {
    }

    /**
     * @param string $listener The listener as Class@method (or \Closure::class), used in logs and x-listener
     */
    public function make(Message $message, callable $callback, string $listener): Handler
    {
        return new Handler($message, $callback, $listener, $this->releaser);
    }
}
