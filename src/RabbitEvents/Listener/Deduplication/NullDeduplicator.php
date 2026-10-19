<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\Deduplication;

use RabbitEvents\Foundation\Message;
use RabbitEvents\Listener\Contracts\Deduplicator;

class NullDeduplicator implements Deduplicator
{
    public function isDuplicate(Message $message, string $queue): bool
    {
        return false;
    }

    public function markHandled(Message $message, string $queue): void
    {
    }
}
