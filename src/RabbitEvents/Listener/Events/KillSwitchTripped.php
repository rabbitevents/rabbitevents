<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\Events;

/**
 * Too many messages failed: the worker stops consuming for `$restartTimeout` seconds.
 */
class KillSwitchTripped
{
    public function __construct(public readonly int $restartTimeout)
    {
    }
}
