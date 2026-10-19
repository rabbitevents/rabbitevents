<?php

declare(strict_types=1);

namespace App\RabbitEvents;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use RabbitEvents\Publisher\ShouldPublish;
use RabbitEvents\Publisher\Support\Publishable;

/**
 * Example demonstrating Distributed Tracing with Laravel Context.
 * All context metadata (e.g. trace_id, user_id) added via Context::add()
 * is sent in the `trace_context` message property and restored on the receiving microservice.
 */
class ContextTracingEvent implements ShouldPublish
{
    use Publishable;

    public function __construct(
        public readonly string $action,
        public readonly array $metadata
    ) {
    }

    public function publishEventKey(): string
    {
        return 'audit.action.performed';
    }

    public function toPublish(): array
    {
        return [
            'action' => $this->action,
            'metadata' => $this->metadata,
        ];
    }
}

// 1. Setting tracing context in the publishing service:
/*
Context::add('trace_id', (string) Str::uuid());
Context::add('actor_id', 42);

// 2. Publish the event - context is attached automatically:
$messageId = ContextTracingEvent::publish('checkout_completed', ['items' => 3]);

// 3. Or using the publish() helper:
$messageId = publish('audit.action.performed', ['status' => 'ok']);
// Returns UUID string, e.g. "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d"
*/
