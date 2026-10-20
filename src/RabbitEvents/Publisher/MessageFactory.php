<?php

declare(strict_types=1);

namespace RabbitEvents\Publisher;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use RabbitEvents\Foundation\Message;
use RabbitEvents\Foundation\Serialization\SerializerRegistry;

class MessageFactory
{
    public function __construct(private SerializerRegistry $registry)
    {
    }

    public function make(ShouldPublish $event): Message
    {
        $rawPayload = $event->toPublish();
        $serializer = $this->registry->resolve($rawPayload);
        $payload = $serializer->serialize($rawPayload);
        
        $messageId = (string) Str::uuid();

        $properties = [
            'content_type' => (string) $payload->contentType(),
            'message_id' => $messageId,
        ];

        if (Facade::getFacadeApplication() && ($contextData = Context::all())) {
            $properties['trace_context'] = $this->sanitizeContextData($contextData);
        }

        if (is_object($rawPayload)) {
            $properties['type'] = get_class($rawPayload);
        }

        $message = new Message($event->publishEventKey(), $payload, $properties);
        $message->setTimestamp(Carbon::now()->getTimestamp());

        return $message;
    }

    protected function sanitizeContextData(array $contextData): array
    {
        return array_map(fn($value) => $this->sanitizeContextValue($value), $contextData);
    }

    /**
     * AMQP tables accept only scalars, null and arrays: convert everything else, never fail.
     */
    protected function sanitizeContextValue(mixed $value): mixed
    {
        return match (true) {
            is_scalar($value), is_null($value) => $value,
            is_array($value) => $this->sanitizeContextData($value),
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \UnitEnum => $value->name,
            $value instanceof \Illuminate\Contracts\Queue\QueueableEntity => get_class($value) . ':' . $value->getQueueableId(),
            $value instanceof \JsonSerializable => $this->sanitizeContextValue($value->jsonSerialize()),
            is_object($value) && method_exists($value, 'toArray') => $this->sanitizeContextValue($value->toArray()),
            $value instanceof \Stringable => (string) $value,
            is_object($value) => $this->objectToArray($value),
            default => get_debug_type($value),
        };
    }

    private function objectToArray(object $value): mixed
    {
        try {
            return $this->sanitizeContextValue(json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return get_class($value);
        }
    }
}
