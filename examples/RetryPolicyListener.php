<?php

declare(strict_types=1);

namespace App\Listeners;

use RabbitEvents\Listener\Attributes\Backoff;
use RabbitEvents\Listener\Attributes\Tries;

/**
 * Overrides `--tries` and `--sleep` for this listener: up to 5 tries, retried after 1s, 5s, then 30s.
 * The same as `public int $tries = 5;` and `public array $backoff = [1, 5, 30];`, or tries() / backoff() methods,
 * which take precedence and get the handler's arguments. Laravel's Illuminate\Queue\Attributes\Tries and Backoff work too.
 */
#[Tries(5)]
#[Backoff(1, 5, 30)]
class RetryPolicyListener
{
    public function handle(array $payload): void
    {
        // Call a service that may be temporarily unavailable; throwing retries the listener.
    }

    public function failed(array $payload, \Throwable $exception): void
    {
        // Called once all 5 tries failed, on the instance that handled the last one.
    }
}
