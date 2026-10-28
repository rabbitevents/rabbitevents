<?php

declare(strict_types=1);

namespace RabbitEvents\Publisher\Outbox;

use RabbitEvents\Foundation\Message;
use RabbitEvents\Publisher\Contracts\Outbox;
use RabbitEvents\Publisher\ShouldPublish;

/**
 * No outbox: the publisher sends every message itself.
 */
class NullOutbox implements Outbox
{
    public function store(ShouldPublish $event, Message $message): bool
    {
        return false;
    }
}
