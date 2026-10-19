<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use RabbitEvents\Listener\Attributes\Listener;

/**
 * Example of an idempotent listener.
 * RabbitMQ delivers a message at least once, so the same payment can arrive twice
 * (e.g. the worker died before acknowledging it). The listener guards itself with a business key.
 * Context propagated from the publisher is available via the Context facade.
 */
#[Listener('payment.received')]
class IdempotentPaymentListener
{
    /**
     * Handle the event payload.
     *
     * @param array $payload The event payload sent from the publisher.
     */
    public function handle(array $payload): void
    {
        // Distributed trace ID propagated from the publishing service:
        $traceId = Context::get('trace_id');

        Log::info('Processing payment event', [
            'trace_id' => $traceId,
            'payment_id' => $payload['payment_id'] ?? null,
            'amount' => $payload['amount'] ?? 0,
        ]);

        // Cache::add() is atomic: only the first delivery of this payment gets past it.
        if (!Cache::add("payments:processed:{$payload['payment_id']}", true, now()->addDay())) {
            return;
        }

        // Process the payment (update balance, send receipt, ...)
    }
}
