<?php

declare(strict_types=1);

namespace RabbitEvents\Foundation\Amqp;

use Interop\Amqp\AmqpContext;
use Interop\Amqp\AmqpDestination;
use Interop\Amqp\AmqpQueue;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use RabbitEvents\Foundation\Exceptions\QueueArgumentsMismatchException;

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

        try {
            $this->context->declareQueue($queue);
        } catch (AMQPProtocolChannelException $e) {
            if ($e->getCode() === 406) {
                throw new QueueArgumentsMismatchException($queueName, $e);
            }

            throw $e;
        }

        return $queue;
    }
}
