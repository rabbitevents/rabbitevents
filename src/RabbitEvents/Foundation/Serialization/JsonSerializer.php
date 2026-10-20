<?php

declare(strict_types=1);

namespace RabbitEvents\Foundation\Serialization;

use RabbitEvents\Foundation\Contracts\ContentType;
use RabbitEvents\Foundation\Contracts\TransportMessage;
use RabbitEvents\Foundation\Contracts\Payload;
use RabbitEvents\Foundation\Contracts\Serializer;
use RabbitEvents\Foundation\Support\JsonPayload;

class JsonSerializer implements Serializer
{
    /**
     * @inheritDoc
     */
    public function serialize(mixed $payload): Payload
    {
        return new JsonPayload($payload);
    }

    /**
     * @inheritDoc
     * @throws \JsonException
     */
    public function deserialize(TransportMessage $message): Payload
    {
        return new JsonPayload(json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function contentType(): ContentType
    {
        return new JsonContentType();
    }

    public function canSerialize(mixed $payload): bool
    {
        return is_array($payload)
            || is_scalar($payload)
            || is_null($payload)
            || $payload instanceof \JsonSerializable
            || $payload instanceof \stdClass;
    }
}
