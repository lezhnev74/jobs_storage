<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Model;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Lezhnev74\Jobs\Model\Incident;
use Lezhnev74\Jobs\Model\Lease;
use Lezhnev74\Jobs\Model\RetryState;
use Lezhnev74\Jobs\Model\Telemetry;
use Lezhnev74\Jobs\Model\TerminalKind;
use Lezhnev74\Jobs\Model\Termination;
use Lezhnev74\Jobs\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Incident::class)]
#[CoversClass(Lease::class)]
#[CoversClass(RetryState::class)]
#[CoversClass(Telemetry::class)]
#[CoversClass(Termination::class)]
final class SubmodelsTest extends TestCase
{
    public function testTerminationNamedConstructors(): void
    {
        $at = Fixtures::at('2026-09-11 12:00:00');

        self::assertSame(TerminalKind::Completed, Termination::completed($at)->kind);
        self::assertSame(TerminalKind::Discarded, Termination::discarded($at)->kind);
        self::assertSame($at, Termination::completed($at)->at);
    }

    public function testLeaseIsLiveStrictlyBeforeTill(): void
    {
        $till = Fixtures::at('2026-09-11 12:10:00');
        $lease = new Lease('w1', $till);

        self::assertTrue($lease->isLive($till->subSecond()));
        self::assertFalse($lease->isLive($till));
        self::assertFalse($lease->isLive($till->addSecond()));
        self::assertSame('w1', $lease->by);
    }

    public function testLeaseIsLiveDefaultsToThePhpClock(): void
    {
        CarbonImmutable::setTestNow(Fixtures::at('2026-09-11 12:00:00'));
        try {
            self::assertTrue((new Lease('w1', Fixtures::at('2026-09-11 12:00:01')))->isLive());
            self::assertFalse((new Lease('w1', Fixtures::at('2026-09-11 11:59:59')))->isLive());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function testIncidentRequiresPositiveCount(): void
    {
        $incident = new Incident(3, 'host-7', Fixtures::at('2026-09-11 12:00:00'));
        self::assertSame(3, $incident->count);

        $this->expectException(InvalidArgumentException::class);
        new Incident(0, 'host-7', Fixtures::at('2026-09-11 12:00:00'));
    }

    public function testRetryStateDefaultsAndReasons(): void
    {
        $fresh = RetryState::fresh();
        self::assertSame([0, 0, 0, null, null], [
            $fresh->attempts,
            $fresh->consecutiveFailures,
            $fresh->consecutiveReschedules,
            $fresh->lastFailReason,
            $fresh->lastRescheduleReason,
        ]);

        $state = new RetryState(5, 2, 0, ['code' => 'timeout'], null);
        self::assertSame('timeout', $state->lastFailReason['code'] ?? null);
    }

    public function testRetryStateRejectsNegativeCounters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RetryState(1, 0, -1);
    }

    public function testTelemetryNone(): void
    {
        $none = Telemetry::none();
        self::assertNull($none->abandonment);
        self::assertNull($none->staleAck);
        self::assertNull($none->lastClaimedBy);

        $abandoned = new Incident(1, 'host-7', Fixtures::at('2026-09-11 12:00:00'));
        $telemetry = new Telemetry(abandonment: $abandoned, lastClaimedBy: 'host-8');
        self::assertSame($abandoned, $telemetry->abandonment);
        self::assertSame('host-8', $telemetry->lastClaimedBy);
    }
}
