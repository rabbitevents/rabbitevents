<?php

declare(strict_types=1);

namespace RabbitEvents\Foundation\Exceptions;

use Throwable;

/**
 * RabbitMQ refuses (406 PRECONDITION_FAILED) to redeclare an existing queue with other flags or arguments.
 */
class QueueArgumentsMismatchException extends \RuntimeException
{
    public function __construct(string $queueName, ?Throwable $previous = null)
    {
        parent::__construct(
            "Queue [{$queueName}] already exists with different flags or arguments. "
            . "RabbitMQ does not allow changing them for an existing queue. "
            . "Either restore the settings the queue was created with (e.g. RABBITEVENTS_QUEUE_DURABLE), "
            . "or drain and delete the queue so it can be redeclared. Broker reply: "
            . ($previous?->getMessage() ?? 'PRECONDITION_FAILED'),
            406,
            $previous
        );
    }
}
