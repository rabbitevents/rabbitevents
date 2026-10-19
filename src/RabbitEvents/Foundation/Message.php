<?php

declare(strict_types=1);

namespace RabbitEvents\Foundation;

use RabbitEvents\Foundation\Contracts\Serializer;
use RabbitEvents\Foundation\Contracts\Payload;
use RabbitEvents\Foundation\Serialization\JsonSerializer;
use RabbitEvents\Foundation\Contracts\TransportMessage;

/**
 * @mixin TransportMessage
 */
class Message
{
    /**
     * @var TransportMessage|null
     */
    private ?TransportMessage $transportMessage = null;

    private bool $failed = false;

    private ?\Throwable $failureException = null;

    private ?string $failedListener = null;

    private bool $acknowledged = false;

    private bool $released = false;

    public function __construct(
        public readonly string $event,
        public readonly Payload $payload,
        private array $properties = []
    ) {
    }

    /**
     * @param TransportMessage $message
     * @param Serializer|null $serializer
     * @return static
     */
    public static function createFromTransportMessage(TransportMessage $message, ?Serializer $serializer = null): static
    {
        $serializer = $serializer ?? new JsonSerializer();
        
        return (new static(
            $message->getProperty('event') ?: $message->getRoutingKey(),
            $serializer->deserialize($message),
            $message->getProperties()
        ))->setTransportMessage($message);
    }

    /**
     * @return TransportMessage
     */
    public function transportMessage(): TransportMessage
    {
        if (is_null($this->transportMessage)) {
            $this->transportMessage = MessageFactory::make(
                $this->event,
                $this->payload,
                $this->properties
            );
        }

        return $this->transportMessage;
    }

    public function __call(string $method, ?array $args)
    {
        return $this->transportMessage()->$method(...$args);
    }

    public function id(): ?string
    {
        return $this->transportMessage()->getMessageId();
    }

    public function attempts(): int
    {
        return $this->getProperty('x-attempts', 0);
    }

    public function increaseAttempts(): self
    {
        $this->setProperty('x-attempts', $this->attempts() + 1);

        return $this;
    }

    /**
     * @param TransportMessage $transportMessage
     * @return Message
     */
    public function setTransportMessage(TransportMessage $transportMessage): self
    {
        $this->transportMessage = $transportMessage;

        return $this;
    }

    public function markAsFailed(?\Throwable $exception = null, ?string $failedListener = null): self
    {
        $this->failed = true;
        $this->failureException = $exception;
        if ($failedListener !== null) {
            $this->failedListener = $failedListener;
        }

        return $this;
    }

    public function hasFailed(): bool
    {
        return $this->failed;
    }

    public function failureException(): ?\Throwable
    {
        return $this->failureException;
    }

    public function failedListener(): ?string
    {
        return $this->failedListener;
    }

    public function markAsAcknowledged(): self
    {
        $this->acknowledged = true;

        return $this;
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged;
    }

    /**
     * Mark that a copy of the message was put back into its queue for another try.
     */
    public function markAsReleased(): self
    {
        $this->released = true;

        return $this;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }
}
