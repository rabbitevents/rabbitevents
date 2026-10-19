<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\Console;

use Illuminate\Support\Carbon;
use RabbitEvents\Foundation\Connection\ConnectionManager;
use RabbitEvents\Foundation\Consumer;
use RabbitEvents\Foundation\Context;
use RabbitEvents\Foundation\Contracts\FailedDestination;
use RabbitEvents\Foundation\Message;
use RabbitEvents\Listener\Dispatcher;
use RabbitEvents\Listener\QueueName;

trait InteractsWithFailedQueue
{
    protected function getFailedQueueName(): string
    {
        return $this->resolveOriginalQueueName() . FailedDestination::SUFFIX;
    }

    protected function resolveOriginalQueueName(): string
    {
        if ($queue = (string) $this->option('queue')) {
            // Accept both the original queue name and the failed queue name typed by the user.
            return str_ends_with($queue, FailedDestination::SUFFIX)
                ? substr($queue, 0, -strlen(FailedDestination::SUFFIX))
                : $queue;
        }

        $service = (string) ($this->option('service') ?: ($this->laravel['config']->get('app.name') ?: 'rabbitevents'));
        $events = $this->option('events')
            ? explode(',', (string) $this->option('events'))
            : ($this->laravel->bound(Dispatcher::class) ? $this->laravel[Dispatcher::class]->getEvents() : []);

        return QueueName::resolve($service, $events);
    }

    protected function getContext(): Context
    {
        $connectionName = $this->option('connection') ?: $this->laravel['config']->get('rabbitevents.default');

        if ($this->laravel->bound(ConnectionManager::class)) {
            return $this->laravel[ConnectionManager::class]->context($connectionName);
        }

        return $this->laravel->make(Context::class);
    }

    /**
     * @param callable(Message, Consumer, Context, FailedDestination): (bool|null) $callback Return false to stop.
     */
    protected function eachFailedMessage(callable $callback, int $timeout = 500, int $maxScan = 10000): void
    {
        $context = $this->getContext();
        $failedQueue = $context->makeFailedQueue($this->resolveOriginalQueueName());
        $consumer = $context->makeConsumer($failedQueue);
        $unhandled = [];
        $seen = [];
        $scanned = 0;

        if ($this->hasOption('limit') && (int) $this->option('limit') > 0) {
            $maxScan = max($maxScan, (int) $this->option('limit'));
        }

        while ($scanned < $maxScan && ($message = $consumer->nextMessage($timeout))) {
            // A message seen twice means the scan wrapped around, e.g. a retried message failed again.
            if ($id = $message->id()) {
                if (isset($seen[$id])) {
                    $unhandled[] = $message;
                    break;
                }

                $seen[$id] = true;
            }

            $scanned++;
            $continue = $callback($message, $consumer, $context, $failedQueue);

            if (!$message->isAcknowledged()) {
                $unhandled[] = $message;
            }

            if ($continue === false) {
                break;
            }
        }

        $consumer->requeue($unhandled);
    }

    protected function extractFailedAt(Message $message): string
    {
        if ($failedAt = $message->getProperty('x-failed-at')) {
            return Carbon::createFromTimestamp((int) $failedAt)->toDateTimeString();
        }

        return 'Unknown';
    }

    protected function extractEvent(Message $message): string
    {
        return $message->getProperty('x-original-routing-key') ?: $message->event;
    }

    protected function extractException(Message $message): string
    {
        return $message->getProperty('x-exception-message')
            ?? $message->getProperty('x-exception-class')
            ?? 'N/A';
    }

}
