<?php

declare(strict_types=1);

namespace RabbitEvents\Foundation\Serialization;

use RabbitEvents\Foundation\Contracts\Serializer;
use RabbitEvents\Foundation\Contracts\ContentType;
use RabbitEvents\Foundation\Exceptions\UnsupportedContentTypeException;

class SerializerRegistry
{
    /**
     * @var array<string, Serializer>
     */
    private array $serializers = [];
    private ?Serializer $default = null;

    public function register(Serializer $serializer, bool $default = false): void
    {
        $this->serializers = [(string) $serializer->contentType() => $serializer] + $this->serializers;

        if ($default) {
            $this->default = $serializer;
        }
    }

    public function getDefault(): Serializer
    {
        if ($this->default) {
            return $this->default;
        }

        if (count($this->serializers) === 1) {
            return $this->default = reset($this->serializers);
        }

        throw new \RuntimeException("Default serializer is not registered.");
    }

    /**
     * @param string|ContentType $contentType
     * @return Serializer
     * @throws UnsupportedContentTypeException
     */
    public function get(string|ContentType $contentType): Serializer
    {
        $contentType = (string) $contentType;

        if (isset($this->serializers[$contentType])) {
            return $this->serializers[$contentType];
        }

        throw new UnsupportedContentTypeException("Unsupported content type: {$contentType}");
    }

    /**
     * Resolve the serializer relative to the payload.
     *
     * @param mixed $payload
     * @return Serializer
     * @throws UnsupportedContentTypeException
     */
    public function resolve(mixed $payload): Serializer
    {
        foreach ($this->serializers as $serializer) {
            if ($serializer->canSerialize($payload)) {
                return $serializer;
            }
        }

        throw new UnsupportedContentTypeException("No serializer found for the given payload");
    }
}
