<?php

declare(strict_types=1);

namespace RabbitEvents\Foundation\Contracts;

interface TransportMessage
{
    /**
     * Get the body of the message.
     *
     * @return string
     */
    public function getBody(): string;

    /**
     * Get a specific property of the message.
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function getProperty(string $name, mixed $default = null): mixed;

    /**
     * Get all properties of the message.
     *
     * @return array
     */
    public function getProperties(): array;

    /**
     * Get the original transport message object (e.g., AmqpMessage).
     *
     * @return mixed
     */
    public function getOrigin(): mixed;

    public function setProperty(string $name, mixed $value): void;

    public function getRoutingKey(): ?string;

    public function setRoutingKey(?string $routingKey): void;

    public function getTimestamp(): ?int;

    public function setTimestamp(int $timestamp): void;

    public function getMessageId(): ?string;

    public function setMessageId(?string $messageId): void;
}
