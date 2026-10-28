<?php

declare(strict_types=1);

namespace RabbitEvents\Foundation\Amqp;

use Interop\Amqp\AmqpContext;
use Interop\Amqp\AmqpDestination;
use Interop\Amqp\AmqpQueue;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use RabbitEvents\Foundation\Exceptions\QueueArgumentsMismatchException;
use Throwable;

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

        return $this->declare($queue);
    }

    /**
     * Declare a queue made by makeAndDeclare() (again): it is created if it was deleted, and left as it is otherwise.
     */
    public function declare(AmqpQueue $queue): AmqpQueue
    {
        try {
            $this->context->declareQueue($queue);
        } catch (Throwable $e) {
            throw $this->isPreconditionFailed($e) ? new QueueArgumentsMismatchException($queue->getQueueName(), $e) : $e;
        }

        return $queue;
    }

    /**
     * RabbitMQ replies 406 PRECONDITION_FAILED, thrown by php-amqplib (enqueue/amqp-lib)
     * or by ext-amqp (enqueue/amqp-ext).
     */
    private function isPreconditionFailed(Throwable $e): bool
    {
        return $e->getCode() === 406
            && ($e instanceof AMQPProtocolChannelException || $e instanceof \AMQPException);
    }
}
