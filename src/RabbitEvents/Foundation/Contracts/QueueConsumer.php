<?php

declare(strict_types=1);

namespace RabbitEvents\Foundation\Contracts;

interface QueueConsumer
{
    /**
     * Receive a message from the queue.
     *
     * @param int $timeout
     * @return TransportMessage|null
     */
    public function receive(int $timeout = 0): ?TransportMessage;

    /**
     * Acknowledge the message.
     *
     * @param TransportMessage $message
     * @return void
     */
    public function acknowledge(TransportMessage $message): void;

    /**
     * Reject the message.
     *
     * @param TransportMessage $message
     * @param bool $requeue
     * @return void
     */
    public function reject(TransportMessage $message, bool $requeue = false): void;

    /**
     * Return received, unacknowledged messages to the queue so they are delivered again in the same order.
     *
     * @param list<TransportMessage> $messages In the order they were received
     */
    public function requeue(array $messages): void;
}
