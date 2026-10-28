<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\Support;

use Illuminate\Support\InteractsWithTime;
use RabbitEvents\Foundation\Contracts\DelaysDelivery;
use RabbitEvents\Foundation\Message;
use RabbitEvents\Foundation\Support\Sender;

class Releaser extends Sender implements DelaysDelivery
{
    use InteractsWithTime;

    /**
     * Put the message back into its queue for another try. It carries `x-attempts` and
     * `x-succeeded-listeners`, so the listeners that already handled it skip it.
     */
    public function release(Message $message, int $delay = 0): void
    {
        $this->setDelay($delay);
        $message->setProperty('event', $message->event);
        $this->send($message);

        $message->markAsReleased();
    }

    /**
     * Transports that can't delay a delivery (e.g. Redis) retry right away. A negative delay is no delay: RabbitMQ
     * refuses a delay queue with a negative `x-message-ttl`.
     */
    public function setDelay(int $delay = 0): void
    {
        if ($this->producer instanceof DelaysDelivery) {
            $this->producer->setDelay(max(0, $this->secondsUntil($delay)));
        }
    }
}
