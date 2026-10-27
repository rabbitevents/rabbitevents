<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\Attributes;

use Attribute;

/**
 * How many times the listener may be tried; 0 means unlimited. Overrides `--tries`, like `$tries`.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Tries
{
    public function __construct(
        public readonly int $tries,
    ) {
    }
}
