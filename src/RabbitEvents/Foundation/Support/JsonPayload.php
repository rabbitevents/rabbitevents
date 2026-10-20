<?php

declare(strict_types=1);

namespace RabbitEvents\Foundation\Support;

use RabbitEvents\Foundation\Contracts\ContentType;
use RabbitEvents\Foundation\Contracts\Payload;
use RabbitEvents\Foundation\Serialization\JsonContentType;

class JsonPayload implements Payload, \JsonSerializable
{
    /**
     * @param mixed $value Anything JsonSerializer accepts: arrays, scalars, null, \JsonSerializable, \stdClass
     */
    public function __construct(private mixed $value)
    {
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function jsonSerialize(): mixed
    {
        return $this->value instanceof \JsonSerializable
            ? $this->value->jsonSerialize()
            : $this->value;
    }

    /**
     * @return string
     * @throws \JsonException
     */
    public function serialize(): string
    {
        return json_encode($this->value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function contentType(): ContentType
    {
        return new JsonContentType();
    }
}
