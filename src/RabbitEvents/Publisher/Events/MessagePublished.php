<?php

declare(strict_types=1);

namespace RabbitEvents\Publisher\Events;

use RabbitEvents\Foundation\Message;

/**
 * The message was sent to the broker: written to the connection (there are no publisher confirms). An outbox
 * dispatches it when it sends a message it took.
 */
class MessagePublished
{
    public function __construct(public readonly Message $message)
    {
    }
}
