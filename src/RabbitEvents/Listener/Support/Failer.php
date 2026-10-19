<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\Support;

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use RabbitEvents\Foundation\Contracts\Destination;
use RabbitEvents\Foundation\Contracts\Producer;
use RabbitEvents\Foundation\Message;
use RabbitEvents\Foundation\Support\Sender;

class Failer extends Sender
{
    public function __construct(
        Destination $destination,
        Producer $producer,
        protected string $queueName
    ) {
        parent::__construct($destination, $producer);
    }

    public function send(Message $message): void
    {
        $exception = $message->failureException();
        $exceptionClass = $exception ? get_class($exception) : 'N/A';
        $exceptionMessage = $exception ? $exception->getMessage() : 'N/A';
        $exceptionTrace = $exception ? $exception->getTraceAsString() : '';

        if (Facade::getFacadeApplication()?->bound('log')) {
            Log::warning(sprintf(
                'RabbitEvents: Message [%s] for event [%s] failed after %d attempts. Dead-lettering to [%s]. Exception: %s: %s',
                $message->id() ?? 'unknown',
                $message->event,
                $message->attempts(),
                $this->queueName . '.failed',
                $exceptionClass,
                $exceptionMessage
            ));
        }

        $originalRoutingKey = $message->header('x-original-routing-key')
            ?: $message->event
            ?: $message->getRoutingKey();

        $message->setHeader('x-failed-at', time());
        $message->setHeader('x-original-routing-key', $originalRoutingKey);
        $message->setHeader('x-exception-class', $exceptionClass);
        $message->setHeader('x-exception-message', $exceptionMessage);
        if ($exceptionTrace) {
            $message->setHeader('x-exception-trace', $exceptionTrace);
        }
        if ($failedListener = $message->failedListener()) {
            $message->setHeader('x-listener', $failedListener);
        }

        $message->setRoutingKey($this->queueName);

        parent::send($message);
    }
}
