<?php

declare(strict_types=1);

namespace RabbitEvents\Foundation\Contracts;

/**
 * A queue holding the messages that failed in another queue. Code checks for this type
 * instead of guessing from a queue name.
 */
interface FailedDestination extends Destination
{
    /**
     * Suffix the transports append to the original queue name.
     */
    public const SUFFIX = '.failed';

    /**
     * The queue whose failed messages this destination holds.
     */
    public function getOriginalQueueName(): string;
}
