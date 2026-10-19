<?php

declare(strict_types=1);

namespace RabbitEvents\Foundation;

use Illuminate\Support\Carbon;
use RabbitEvents\Foundation\Contracts\QueueConsumer;
use RabbitEvents\Foundation\Contracts\Serializer;
use RabbitEvents\Foundation\Contracts\TransportMessage;
use RabbitEvents\Foundation\Exceptions\ConnectionLostException;
use RabbitEvents\Foundation\Exceptions\UnsupportedContentTypeException;
use RabbitEvents\Foundation\Serialization\SerializerRegistry;

/**
 * @mixin QueueConsumer
 */
class Consumer
{
    public function __construct(private QueueConsumer $consumer, private SerializerRegistry $registry)
    {
    }

    public function __call(string $method, array $args)
    {
        return $this->consumer->$method(...$args);
    }

    /**
     * Receives a Message from the queue and returns Message object
     */
    public function nextMessage(int $timeout = 0): ?Message
    {
        if (!$transportMessage = $this->receiveMessage($timeout)) {
            return null;
        }

        // Set timestamp only if this message was not released before
        if (!$transportMessage->getTimestamp()) {
            $transportMessage->setTimestamp(Carbon::now()->getTimestamp());
        }

        if (!$transportMessage->getProperty('event')) {
            $transportMessage->setProperty('event', $transportMessage->getRoutingKey());
        }

        try {
            $content_type = $transportMessage->getProperty('content_type');

            $serializer = $content_type ? $this->registry->get($content_type) : $this->registry->getDefault();

            return Message::createFromTransportMessage($transportMessage, $serializer)->increaseAttempts();
        } catch (UnsupportedContentTypeException $e) {
            $this->consumer->reject($transportMessage, false);
            throw $e;
        }
    }

    protected function receiveMessage(int $timeout = 0): ?TransportMessage
    {
        try {
            return $this->consumer->receive($timeout);
        } catch (\Throwable $exception) {
            throw new ConnectionLostException($exception);
        }
    }

    public function acknowledge(Message $message): void
    {
        $message->markAsAcknowledged();
        $this->consumer->acknowledge($message->transportMessage());
    }

    public function reject(Message $message, bool $requeue = false): void
    {
        $this->consumer->reject($message->transportMessage(), $requeue);
    }

    /**
     * @param list<Message> $messages In the order they were received
     */
    public function requeue(array $messages): void
    {
        $this->consumer->requeue(array_map(static fn(Message $message) => $message->transportMessage(), $messages));
    }
}
