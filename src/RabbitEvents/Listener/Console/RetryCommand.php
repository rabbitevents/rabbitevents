<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\Console;

use Illuminate\Console\Command;
use RabbitEvents\Foundation\Consumer;
use RabbitEvents\Foundation\Context;
use RabbitEvents\Foundation\Contracts\Destination;
use RabbitEvents\Foundation\Contracts\FailedDestination;
use RabbitEvents\Foundation\Contracts\Producer;
use RabbitEvents\Foundation\Message;
use RabbitEvents\Foundation\Support\Sender;
use RabbitEvents\Listener\Message\Handler;
use RabbitEvents\Listener\Support\Failer;

class RetryCommand extends Command
{
    use InteractsWithFailedQueue;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rabbitevents:retry
                            {id? : The ID of the failed message to retry or "all"}
                            {--events= : The events the queue was listening for}
                            {--all : Retry all failed messages}
                            {--queue= : The failed queue name (e.g. service:events.failed)}
                            {--connection= : The connection name}
                            {--service= : The service name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retry a failed message or all failed messages back onto the queue';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $id = (string) $this->argument('id');
        $all = (bool) $this->option('all') || $id === 'all';

        if (!$all && empty($id)) {
            $this->error('Please specify the message ID to retry or pass the --all option.');
            return 1;
        }

        $targetId = $all ? null : $id;
        $retried = $this->retryMessages($targetId);

        if (!$all && $retried === 0) {
            $this->error("Failed message [{$id}] not found.");
            return 1;
        }

        if ($all) {
            $this->info("Retried {$retried} failed message(s).");
        }

        return 0;
    }

    protected function retryMessages(?string $targetId): int
    {
        $producer = null;
        $targetQueues = [];
        $retried = 0;

        $this->eachFailedMessage(function (Message $message, Consumer $consumer, Context $context, FailedDestination $failedQueue) use ($targetId, &$producer, &$targetQueues, &$retried) {
            $msgId = $message->id();

            if (is_null($targetId) || $msgId === $targetId) {
                $producer ??= $context->createProducer();
                // Declared once per queue, not once per message.
                $queueName = $this->getTargetQueueName($message, $failedQueue);
                $targetQueues[$queueName] ??= $context->makeQueue($queueName, [], $context->makeTopic());
                $this->republishMessage($message, $producer, $targetQueues[$queueName]);
                $consumer->acknowledge($message);

                $retried++;
                $this->info("The failed message [{$msgId}] has been pushed back onto the queue!");

                if (!is_null($targetId)) {
                    return false;
                }
            }

            return true;
        }, maxScan: is_null($targetId) ? PHP_INT_MAX : 10000);

        return $retried;
    }

    protected function getTargetQueueName(Message $message, FailedDestination $failedQueue): string
    {
        return $message->getProperty(Failer::ORIGINAL_QUEUE) ?: $failedQueue->getOriginalQueueName();
    }

    protected function republishMessage(Message $message, Producer $producer, Destination $destination): void
    {
        $routingKey = $this->extractEvent($message);
        $message->setRoutingKey($routingKey);
        $message->setProperty('event', $routingKey);
        $message->setProperty('x-attempts', 0);
        $message->setProperty(Handler::ATTEMPTS_PROPERTY, null);

        foreach (Failer::FAILURE_PROPERTIES as $property) {
            $message->setProperty($property, null);
        }

        (new Sender($destination, $producer))->send($message);
    }
}
