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
            'headers' => [
                'x-deduplication-header' => $messageId,
            ],
        ];

        if (Facade::getFacadeApplication() && ($contextData = Context::all())) {
            $properties['headers']['trace_context'] = $this->sanitizeContextData($contextData);
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
        return array_map(function ($value) {
            if (is_scalar($value) || is_null($value)) {
                return $value;
            }

            if (is_array($value)) {
                return $this->sanitizeContextData($value);
            }

            if ($value instanceof \Illuminate\Contracts\Queue\QueueableEntity) {
                return get_class($value) . ':' . $value->getQueueableId();
            }

            if ($value instanceof \JsonSerializable) {
                $serialized = $value->jsonSerialize();
                return is_array($serialized) ? $this->sanitizeContextData($serialized) : (string) $serialized;
            }

            if (is_object($value)) {
                if (method_exists($value, 'toArray')) {
                    return $this->sanitizeContextData($value->toArray());
                }

                if (method_exists($value, '__toString')) {
                    return (string) $value;
                }

                return json_decode(json_encode($value), true) ?? get_class($value);
            }

            return (string) $value;
        }, $contextData);
    }
}
