<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\Attributes;

use Attribute;

/**
 * Seconds before the next try: one value for every retry, or a value per attempt whose last one repeats
 * (`#[Backoff(1, 5, 30)]`). Overrides `--sleep`, like `$backoff`.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Backoff
{
    public readonly array|int $backoff;

    public function __construct(array|int ...$backoff)
    {
        $this->backoff = count($backoff) === 1 ? $backoff[0] : $backoff;
    }
}
