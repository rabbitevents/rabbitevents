<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\Support;

use Illuminate\Support\InteractsWithTime;
use InvalidArgumentException;

/**
 * Decides when a worker should stop consuming because too many messages fail, e.g. while a database
 * the listeners need is down. Without it every message would exhaust its tries and land in the
 * failed queue. It only counts outcomes of this worker, in memory: no storage is needed.
 *
 * After a restart one message is a probe: if it fails, the switch trips again right away.
 */
class KillSwitch
{
    use InteractsWithTime;

    /**
     * Outcomes of the tracking period, per second: [timestamp => [handled, failed]].
     *
     * @var array<int, array{int, int}>
     */
    private array $outcomes = [];

    private bool $probing = false;

    /**
     * @param int $activationThreshold Messages handled in the tracking period before the switch can trip
     * @param int $tripThreshold Percentage of failed messages that trips the switch
     * @param int $trackingPeriod Seconds of outcomes taken into account
     * @param int $restartTimeout Seconds the worker stops consuming after the switch tripped
     */
    public function __construct(
        public readonly int $activationThreshold = 10,
        public readonly int $tripThreshold = 15,
        public readonly int $trackingPeriod = 60,
        public readonly int $restartTimeout = 60,
    ) {
        if ($activationThreshold < 1 || $trackingPeriod < 1 || $restartTimeout < 1) {
            throw new InvalidArgumentException(
                'Kill switch activation threshold, tracking period and restart timeout must be at least 1.'
            );
        }

        if ($tripThreshold < 1 || $tripThreshold > 100) {
            throw new InvalidArgumentException('Kill switch trip threshold must be a percentage from 1 to 100.');
        }
    }

    /**
     * Record the outcome of a message.
     *
     * @return bool True if the switch tripped: the worker should stop consuming for `restartTimeout` seconds
     */
    public function record(bool $failed): bool
    {
        if ($this->probing) {
            $this->probing = false;

            return $failed;
        }

        $now = $this->currentTime();
        $this->outcomes[$now][0] = ($this->outcomes[$now][0] ?? 0) + 1;
        $this->outcomes[$now][1] = ($this->outcomes[$now][1] ?? 0) + (int) $failed;

        $handled = $failedCount = 0;

        foreach ($this->outcomes as $second => [$secondHandled, $secondFailed]) {
            if ($second <= $now - $this->trackingPeriod) {
                unset($this->outcomes[$second]);
                continue;
            }

            $handled += $secondHandled;
            $failedCount += $secondFailed;
        }

        if ($handled < $this->activationThreshold || $failedCount * 100 < $this->tripThreshold * $handled) {
            return false;
        }

        $this->outcomes = [];

        return true;
    }

    /**
     * The worker consumes again: the next message decides whether the switch trips again.
     */
    public function restart(): void
    {
        $this->outcomes = [];
        $this->probing = true;
    }
}
