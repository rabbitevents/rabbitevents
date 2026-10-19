<?php

declare(strict_types=1);

namespace RabbitEvents\Foundation\Amqp;

use Interop\Amqp\AmqpContext;
use Interop\Amqp\AmqpDestination;
use Interop\Amqp\AmqpQueue;

class QueueFactory
{
    public function __construct(private readonly AmqpContext $context, private readonly Connection $connection)
    {
    }

    /**
     * @param string $queueName
     * @return AmqpQueue
     */
    public function makeAndDeclare(string $queueName): AmqpQueue
    {
        $queue = $this->context->createQueue($queueName);

        if ($this->connection->getConfig('durable', true)) {
            $queue->addFlag(AmqpDestination::FLAG_DURABLE);
        }

        (new DeadLetterConfigurator($this->context, $this->connection))->configure($queue, $queueName);

        if (!str_ends_with($queueName, '.failed')) {
            $this->connection->getDeduplicationStrategy()->prepareQueue($queue);
        }

        $this->context->declareQueue($queue);

        return $queue;
    }
}
