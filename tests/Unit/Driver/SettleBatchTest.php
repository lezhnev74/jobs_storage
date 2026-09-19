<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Driver;

use Lezhnev74\Jobs\Driver\Support\SettleBatch;
use Lezhnev74\Jobs\Model\AckReport;
use Lezhnev74\Jobs\Model\Outcome;
use Lezhnev74\Jobs\Model\Reason;
use Lezhnev74\Jobs\Model\Settlement;
use Lezhnev74\Jobs\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SettleBatch::class)]
final class SettleBatchTest extends TestCase
{
    public function testEmptyBatchIsNeitherUniformNorDispatchable(): void
    {
        $batch = SettleBatch::of();

        self::assertTrue($batch->isEmpty());
        self::assertFalse($batch->isUniform());
        self::assertNull($batch->outcome);
        self::assertSame([], $batch->ids());
    }

    public function testSameOutcomeAndInputsIsUniform(): void
    {
        $at = Fixtures::at('2026-09-11 12:00:00');
        $a = Fixtures::job();
        $b = Fixtures::job();

        $batch = SettleBatch::of(
            Settlement::fail($a, new Reason(['code' => 'timeout']), $at),
            Settlement::fail($b, new Reason(['code' => 'timeout']), $at->setTimezone('Europe/Berlin')),
        );

        self::assertFalse($batch->isEmpty());
        self::assertTrue($batch->isUniform());
        self::assertSame(Outcome::Fail, $batch->outcome);
        self::assertSame([$a->id, $b->id], $batch->ids());
    }

    public function testMixedOutcomesAreNotUniform(): void
    {
        $batch = SettleBatch::of(Settlement::complete(Fixtures::job()), Settlement::release(Fixtures::job()));

        self::assertFalse($batch->isUniform());
        self::assertNull($batch->outcome);
    }

    public function testSameOutcomeWithDifferentReasonIsNotUniform(): void
    {
        $batch = SettleBatch::of(
            Settlement::discard(Fixtures::job(), new Reason(['code' => 'a'])),
            Settlement::discard(Fixtures::job(), new Reason(['code' => 'b'])),
        );

        self::assertFalse($batch->isUniform());
    }

    public function testSameOutcomeWithDifferentAvailableAtIsNotUniform(): void
    {
        $why = new Reason(['code' => 'later']);
        $batch = SettleBatch::of(
            Settlement::reschedule(Fixtures::job(), $why, Fixtures::at('2026-09-11 12:00:00')),
            Settlement::reschedule(Fixtures::job(), $why, Fixtures::at('2026-09-11 12:00:01')),
        );

        self::assertFalse($batch->isUniform());
    }

    public function testStaleToCountExcludesReleases(): void
    {
        $completed = Fixtures::job();
        $released = Fixtures::job();
        $acked = Fixtures::job();
        $batch = SettleBatch::of(
            Settlement::complete($completed),
            Settlement::release($released),
            Settlement::complete($acked),
        );
        $report = new AckReport([$acked->id], [$completed->id, $released->id]);

        self::assertSame([$completed->id], $batch->staleToCount($report));
    }
}
