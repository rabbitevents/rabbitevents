<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\Contracts;

use RabbitEvents\Foundation\Message;

/**
 * Skips messages that were already handled in a queue, e.g. redelivered after a worker died before acknowledging.
 * The same message is legitimately delivered to every queue bound to its event, so state must be kept per queue.
 */
interface Deduplicator
{
    public function isDuplicate(Message $message, string $queue): bool;

    public function markHandled(Message $message, string $queue): void;
}
