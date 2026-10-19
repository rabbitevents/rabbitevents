<?php

declare(strict_types=1);

namespace RabbitEvents\Foundation\Amqp;

use Interop\Amqp\AmqpContext;
use Interop\Amqp\AmqpQueue;
use Interop\Amqp\AmqpTopic;
use Interop\Amqp\Impl\AmqpBind;

class DeadLetterConfigurator
{
    public function __construct(
        private readonly AmqpContext $context,
        private readonly Connection $connection
    ) {
    }

    public function configure(AmqpQueue $queue, string $queueName): void
    {
        if (!$this->shouldConfigure($queueName)) {
            return;
        }

        $exchange = $this->declareDeadLetterExchange();
        $failedQueue = $this->declareFailedQueue($queueName);

        $this->bindFailedQueue($exchange, $failedQueue, $queueName);
    }

    protected function shouldConfigure(string $queueName): bool
    {
        return (bool) $this->connection->getConfig('dlx.enabled', true)
            && !str_ends_with($queueName, '.failed');
    }

    protected function declareDeadLetterExchange(): AmqpTopic
    {
        $exchangeName = (string) $this->connection->getConfig('dlx.exchange', 'rabbitevents.dlx');

        return (new DestinationTopicFactory($this->context, $this->connection))
            ->makeAndDeclare($exchangeName);
    }

    protected function declareFailedQueue(string $queueName): AmqpQueue
    {
        return (new QueueFactory($this->context, $this->connection))
            ->makeAndDeclare($this->resolveFailedQueueName($queueName));
    }

    protected function resolveFailedQueueName(string $queueName): string
    {
        return "{$queueName}.failed";
    }

    protected function bindFailedQueue(AmqpTopic $exchange, AmqpQueue $failedQueue, string $queueName): void
    {
        $this->context->bind(new AmqpBind($exchange, $failedQueue, $queueName));
    }
}
