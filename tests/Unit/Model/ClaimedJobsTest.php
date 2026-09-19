<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Model;

use Lezhnev74\Jobs\Model\AckReport;
use Lezhnev74\Jobs\Model\ClaimedJobs;
use Lezhnev74\Jobs\Model\Job;
use Lezhnev74\Jobs\Model\LeaseToken;
use Lezhnev74\Jobs\Model\WorkerId;
use Lezhnev74\Jobs\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClaimedJobs::class)]
#[UsesClass(AckReport::class)]
#[UsesClass(Job::class)]
final class ClaimedJobsTest extends TestCase
{
    public function testEmptyClaimStillCarriesTheLease(): void
    {
        $token = LeaseToken::generate(new WorkerId('w1'));
        $until = Fixtures::at('2026-09-11 12:10:00');

        $claimed = new ClaimedJobs($token, $until, []);

        self::assertTrue($claimed->isEmpty());
        self::assertCount(0, $claimed);
        self::assertSame([], $claimed->ids());
        self::assertSame($token, $claimed->token);
        self::assertSame($until, $claimed->leasedUntil);
    }

    public function testExposesJobsAndIdsInOrder(): void
    {
        $a = Fixtures::job();
        $b = Fixtures::job();

        $claimed = new ClaimedJobs(LeaseToken::generate(new WorkerId('w1')), Fixtures::at('2026-09-11 12:10:00'), [$a, $b]);

        self::assertFalse($claimed->isEmpty());
        self::assertCount(2, $claimed);
        self::assertSame([$a, $b], $claimed->jobs);
        self::assertSame([$a->id, $b->id], $claimed->ids());
    }

    /** Wiring only - the filter semantics themselves are covered in JobTest. */
    public function testHelpersFilterTheClaimedJobs(): void
    {
        [$acked, $stale, $untouched] = [Fixtures::job(), Fixtures::job(), Fixtures::job()];
        $claimed = self::claim([$acked, $stale, $untouched]);
        $report = new AckReport([$acked->id], [$stale->id]);

        self::assertSame([$acked], $claimed->only($acked->id));
        self::assertSame([$stale, $untouched], $claimed->except($acked->id));
        self::assertSame([$stale], $claimed->stale($report));
        self::assertSame([$untouched], $claimed->unsettled($report));
    }

    public function testHelpersOnAnEmptyClaimReturnNothing(): void
    {
        $claimed = self::claim([]);
        $report = new AckReport([Fixtures::job()->id], []);

        self::assertSame([], $claimed->only(Fixtures::job()->id));
        self::assertSame([], $claimed->except(Fixtures::job()->id));
        self::assertSame([], $claimed->stale($report));
        self::assertSame([], $claimed->unsettled($report));
    }

    /** @param list<Job> $jobs */
    private static function claim(array $jobs): ClaimedJobs
    {
        return new ClaimedJobs(LeaseToken::generate(new WorkerId('w1')), Fixtures::at('2026-09-11 12:10:00'), $jobs);
    }
}
