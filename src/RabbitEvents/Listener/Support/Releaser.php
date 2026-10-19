<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\Support;

use Illuminate\Support\InteractsWithTime;
use RabbitEvents\Foundation\Contracts\DelaysDelivery;
use RabbitEvents\Foundation\Support\Sender;

class Releaser extends Sender implements DelaysDelivery
{
    use InteractsWithTime;

    /**
     * Transports that can't delay a delivery (e.g. Redis) retry right away.
     */
    public function setDelay(int $delay = 0): void
    {
        if ($this->producer instanceof DelaysDelivery) {
            $this->producer->setDelay($this->secondsUntil($delay));
        }
    }
}
