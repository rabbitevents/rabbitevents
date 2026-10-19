<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\Support;

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RabbitEvents\Foundation\Contracts\FailedDestination;
use RabbitEvents\Foundation\Contracts\Producer;
use RabbitEvents\Foundation\Message;
use RabbitEvents\Foundation\Support\Sender;

class Failer extends Sender
{
    /**
     * Max size of a failure property in bytes: AMQP sends properties as headers, which must fit into a single frame (frame_max is 128KB by default).
     */
    public const MAX_PROPERTY_LENGTH = 4096;

    /**
     * Properties describing the latest failure. They are cleared when the message is retried.
     */
    public const FAILURE_PROPERTIES = [
        'x-failed-at',
        'x-original-queue',
        'x-exception-class',
        'x-exception-message',
        'x-exception-trace',
        'x-listener',
    ];

    public function __construct(private readonly FailedDestination $failedQueue, Producer $producer)
    {
        parent::__construct($failedQueue, $producer);
    }

    public function send(Message $message): void
    {
        // Messages published by RabbitEvents 9.x have no ID; give one so `retry` and `forget` can address them.
        if (!$message->id()) {
            $message->setMessageId((string) Str::uuid());
        }

        $exception = $message->failureException();
        $exceptionClass = $exception ? get_class($exception) : 'N/A';
        $exceptionMessage = $exception ? mb_strcut($exception->getMessage(), 0, self::MAX_PROPERTY_LENGTH) : 'N/A';
        $exceptionTrace = $exception ? mb_strcut($exception->getTraceAsString(), 0, self::MAX_PROPERTY_LENGTH) : '';

        if (Facade::getFacadeApplication()?->bound('log')) {
            Log::warning(sprintf(
                'RabbitEvents: Message [%s] for event [%s] failed after %d attempts in [%s]. Moving it to the failed queue. Exception: %s: %s',
                $message->id(),
                $message->event,
                $message->attempts(),
                $this->failedQueue->getOriginalQueueName(),
                $exceptionClass,
                $exceptionMessage
            ));
        }

        $originalRoutingKey = $message->getProperty('x-original-routing-key')
            ?: $message->event
            ?: $message->getRoutingKey();

        $message->setProperty('x-failed-at', time());
        $message->setProperty('x-original-routing-key', $originalRoutingKey);
        $message->setProperty('x-original-queue', $this->failedQueue->getOriginalQueueName());
        $message->setProperty('x-exception-class', $exceptionClass);
        $message->setProperty('x-exception-message', $exceptionMessage);
        if ($exceptionTrace) {
            $message->setProperty('x-exception-trace', $exceptionTrace);
        }
        if ($failedListener = $message->failedListener()) {
            $message->setProperty('x-listener', $failedListener);
        }

        parent::send($message);
    }
}
