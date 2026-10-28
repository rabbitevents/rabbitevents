<?php

declare(strict_types=1);

namespace RabbitEvents\Publisher\Contracts;

use RabbitEvents\Foundation\Message;
use RabbitEvents\Publisher\ShouldPublish;

/**
 * Takes messages to deliver them itself, e.g. from a table written in the transaction of the event. The publisher
 * calls it when it publishes the event: an `afterCommit` event only after the commit. Bound to NullOutbox; the
 * `rabbitevents/outbox` package implements it.
 */
interface Outbox
{
    /**
     * @return bool Whether the outbox took the message; if not, the publisher sends it itself.
     */
    public function store(ShouldPublish $event, Message $message): bool;
}
