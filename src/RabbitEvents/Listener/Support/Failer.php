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

    public const FAILED_AT = 'x-failed-at';
    public const ORIGINAL_QUEUE = 'x-original-queue';
    public const ORIGINAL_ROUTING_KEY = 'x-original-routing-key';
    public const EXCEPTION_CLASS = 'x-exception-class';
    public const EXCEPTION_MESSAGE = 'x-exception-message';
    public const EXCEPTION_TRACE = 'x-exception-trace';
    public const LISTENER = 'x-listener';

    /**
     * Properties describing the latest failure. They are cleared when the message is retried.
     */
    public const FAILURE_PROPERTIES = [
        self::FAILED_AT,
        self::ORIGINAL_QUEUE,
        self::ORIGINAL_ROUTING_KEY,
        self::EXCEPTION_CLASS,
        self::EXCEPTION_MESSAGE,
        self::EXCEPTION_TRACE,
        self::LISTENER,
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

        $originalRoutingKey = $message->getProperty(self::ORIGINAL_ROUTING_KEY)
            ?: $message->event
            ?: $message->getRoutingKey();

        $message->setProperty(self::FAILED_AT, time());
        $message->setProperty(self::ORIGINAL_ROUTING_KEY, $originalRoutingKey);
        $message->setProperty(self::ORIGINAL_QUEUE, $this->failedQueue->getOriginalQueueName());
        $message->setProperty(self::EXCEPTION_CLASS, $exceptionClass);
        $message->setProperty(self::EXCEPTION_MESSAGE, $exceptionMessage);
        if ($exceptionTrace) {
            $message->setProperty(self::EXCEPTION_TRACE, $exceptionTrace);
        }
        if ($failedListener = $message->failedListener()) {
            $message->setProperty(self::LISTENER, $failedListener);
        }

        parent::send($message);
    }
}
