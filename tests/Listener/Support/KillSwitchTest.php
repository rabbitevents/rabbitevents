<?php

declare(strict_types=1);

namespace RabbitEvents\Tests\Listener\Support;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use RabbitEvents\Listener\Support\KillSwitch;
use RabbitEvents\Tests\Listener\TestCase;

class KillSwitchTest extends TestCase
{
    protected function setUp(): void
    {
        Carbon::setTestNow('2026-10-23 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
    }

    public function testDoesNotTripBeforeActivationThreshold(): void
    {
        $switch = new KillSwitch(activationThreshold: 10);

        for ($i = 0; $i < 9; $i++) {
            self::assertFalse($switch->record(failed: true));
        }
    }

    public function testTripsWhenFailureRateReachesThreshold(): void
    {
        $switch = new KillSwitch(activationThreshold: 10, tripThreshold: 20);

        self::assertFalse($this->recordMany($switch, handled: 8, failed: 0));
        self::assertFalse($switch->record(failed: true));
        // 2 of 10 failed: 20%
        self::assertTrue($switch->record(failed: true));
    }

    public function testDoesNotTripBelowThreshold(): void
    {
        $switch = new KillSwitch(activationThreshold: 10, tripThreshold: 20);

        self::assertFalse($switch->record(failed: true));
        self::assertFalse($this->recordMany($switch, handled: 30, failed: 0));
        self::assertFalse($switch->record(failed: true));
    }

    public function testForgetsOutcomesOlderThanTrackingPeriod(): void
    {
        $switch = new KillSwitch(activationThreshold: 4, tripThreshold: 50, trackingPeriod: 60);

        self::assertFalse($this->recordMany($switch, handled: 3, failed: 3));

        Carbon::setTestNow(Carbon::now()->addSeconds(60));

        // The three failures left the period: 1 of 4 recent messages failed
        self::assertFalse($this->recordMany($switch, handled: 3, failed: 0));
        self::assertFalse($switch->record(failed: true));
        self::assertFalse($switch->record(failed: false));
    }

    public function testCountsAgainFromZeroAfterTripping(): void
    {
        $switch = new KillSwitch(activationThreshold: 2, tripThreshold: 100);

        self::assertFalse($switch->record(failed: true));
        self::assertTrue($switch->record(failed: true));

        $switch->restart();
        self::assertFalse($switch->record(failed: false));

        self::assertFalse($switch->record(failed: true));
        self::assertTrue($switch->record(failed: true));
    }

    public function testFailedProbeAfterRestartTripsAgainAtOnce(): void
    {
        $switch = new KillSwitch(activationThreshold: 10);

        $switch->restart();

        self::assertTrue($switch->record(failed: true));
    }

    public function testSucceededProbeAfterRestartClosesTheSwitch(): void
    {
        $switch = new KillSwitch(activationThreshold: 10);

        $switch->restart();
        self::assertFalse($switch->record(failed: false));

        // Back to counting: one failure is below the activation threshold
        self::assertFalse($switch->record(failed: true));
    }

    #[DataProvider('invalidSettings')]
    public function testRejectsInvalidSettings(array $settings): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new KillSwitch(...$settings);
    }

    public static function invalidSettings(): array
    {
        return [
            'activation threshold' => [['activationThreshold' => 0]],
            'trip threshold 0' => [['tripThreshold' => 0]],
            'trip threshold over 100' => [['tripThreshold' => 101]],
            'tracking period' => [['trackingPeriod' => 0]],
            'restart timeout' => [['restartTimeout' => 0]],
        ];
    }

    /**
     * @return bool Whether any of the records tripped the switch
     */
    private function recordMany(KillSwitch $switch, int $handled, int $failed): bool
    {
        $tripped = false;

        for ($i = 0; $i < $handled; $i++) {
            $tripped = $switch->record(failed: $i < $failed) || $tripped;
        }

        return $tripped;
    }
}
