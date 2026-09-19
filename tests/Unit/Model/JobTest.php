<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Model;

use Carbon\CarbonImmutable;
use Lezhnev74\Jobs\Model\AckReport;
use Lezhnev74\Jobs\Model\Job;
use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Model\Lease;
use Lezhnev74\Jobs\Model\Termination;
use Lezhnev74\Jobs\Query\State;
use Lezhnev74\Jobs\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Job::class)]
#[UsesClass(AckReport::class)]
#[UsesClass(Lease::class)]
#[UsesClass(Termination::class)]
final class JobTest extends TestCase
{
    private const T0 = '2026-09-11 12:00:00'; // available_at
    private const T1 = '2026-09-11 12:10:00'; // consumed_till

    /**
     * Terminals win regardless of lease or schedule; otherwise the same snapshot flips state as the clock moves.
     *
     * @return iterable<string, array{?Termination, ?Lease, string, State}>
     */
    public static function matrix(): iterable
    {
        $lease = new Lease('w1', Fixtures::at(self::T1));
        $completed = Termination::completed(Fixtures::at('2026-09-11 12:05:00'));
        $discarded = Termination::discarded(Fixtures::at('2026-09-11 12:05:00'));

        yield 'unleased, before available_at' => [null, null, '2026-09-11 11:59:59', State::Scheduled];
        yield 'unleased, at available_at' => [null, null, self::T0, State::Claimable];
        yield 'unleased, after available_at' => [null, null, '2026-09-11 12:00:01', State::Claimable];
        yield 'leased, before available_at' => [null, $lease, '2026-09-11 11:59:59', State::Claimed];
        yield 'leased, live lease' => [null, $lease, '2026-09-11 12:05:00', State::Claimed];
        yield 'leased, lease expires exactly now' => [null, $lease, self::T1, State::Claimable];
        yield 'leased, expired lease' => [null, $lease, '2026-09-11 12:10:01', State::Claimable];
        yield 'completed, live lease in row' => [$completed, $lease, '2026-09-11 12:05:00', State::Completed];
        yield 'completed, far future' => [$completed, null, '2030-01-01 00:00:00', State::Completed];
        yield 'discarded, before available_at' => [$discarded, null, '2026-09-11 11:00:00', State::Discarded];
        yield 'discarded, live lease in row' => [$discarded, $lease, '2026-09-11 12:05:00', State::Discarded];
    }

    #[DataProvider('matrix')]
    public function testStateInference(?Termination $termination, ?Lease $lease, string $now, State $expected): void
    {
        $job = Fixtures::job(availableAt: Fixtures::at(self::T0), termination: $termination, lease: $lease);

        self::assertSame($expected, $job->state(Fixtures::at($now)));
    }

    public function testStateDefaultsToThePhpClock(): void
    {
        CarbonImmutable::setTestNow(Fixtures::at('2026-09-11 12:05:00'));
        try {
            $lease = new Lease('w1', Fixtures::at(self::T1));
            $job = Fixtures::job(availableAt: Fixtures::at(self::T0), lease: $lease);

            self::assertSame(State::Claimed, $job->state());

            CarbonImmutable::setTestNow(Fixtures::at('2026-09-11 12:11:00'));
            self::assertSame(State::Claimable, $job->state());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function testStateIsNeverStored(): void
    {
        $job = Fixtures::job(availableAt: Fixtures::at(self::T0));

        self::assertSame(State::Scheduled, $job->state(Fixtures::at('2026-09-11 11:00:00')));
        self::assertSame(State::Claimable, $job->state(Fixtures::at('2026-09-11 13:00:00')));
    }

    public function testOnlyAndExceptAreComplementsPreservingInputOrder(): void
    {
        [$a, $b, $c] = [Fixtures::job(), Fixtures::job(), Fixtures::job()];
        $jobs = [$a, $b, $c];

        self::assertSame([$a, $c], Job::only($jobs, $c->id, $a->id));
        self::assertSame([$b], Job::except($jobs, $c->id, $a->id));
    }

    public function testOnlyIgnoresIdsAbsentFromTheList(): void
    {
        $a = Fixtures::job();

        self::assertSame([$a], Job::only([$a], JobId::new(), JobId::fromString($a->id->value)));
        self::assertSame([], Job::only([$a], JobId::new()));
        self::assertSame([], Job::only([$a]));
        self::assertSame([$a], Job::except([$a]));
    }

    public function testUnreportedReturnsOnlyJobsNoReportMentions(): void
    {
        [$acked, $stale, $untouched] = [Fixtures::job(), Fixtures::job(), Fixtures::job()];
        $jobs = [$acked, $stale, $untouched];
        $report = new AckReport([$acked->id], [$stale->id]);

        self::assertSame([$untouched], Job::unreported($jobs, $report));
        self::assertSame([$stale], Job::stale($jobs, $report));
    }

    public function testUnreportedWithoutReportsReturnsEveryJob(): void
    {
        $jobs = [Fixtures::job(), Fixtures::job()];

        self::assertSame($jobs, Job::unreported($jobs));
        self::assertSame([], Job::stale($jobs));
    }

    public function testUnreportedIsEmptyOnceEveryJobIsReported(): void
    {
        [$a, $b] = [Fixtures::job(), Fixtures::job()];

        self::assertSame([], Job::unreported([$a, $b], new AckReport([$a->id], [$b->id])));
    }

    public function testFiltersMergeReportsAcrossSettleRounds(): void
    {
        [$a, $b, $c] = [Fixtures::job(), Fixtures::job(), Fixtures::job()];
        $jobs = [$a, $b, $c];

        $first = new AckReport([$a->id], []);
        $second = new AckReport([], [$b->id]);

        self::assertSame([$c], Job::unreported($jobs, $first, $second));
        self::assertSame([$b], Job::stale($jobs, $first, $second));
    }

    public function testAJobAckedThenFencedOutCountsAsStale(): void
    {
        $job = Fixtures::job();

        $rounds = [new AckReport([$job->id], []), new AckReport([], [$job->id])];

        self::assertSame([$job], Job::stale([$job], ...$rounds));
        self::assertSame([], Job::unreported([$job], ...$rounds));
    }

    public function testEveryFilterHandlesAnEmptyJobList(): void
    {
        $report = new AckReport([JobId::new()], [JobId::new()]);

        self::assertSame([], Job::only([], JobId::new()));
        self::assertSame([], Job::except([], JobId::new()));
        self::assertSame([], Job::unreported([], $report));
        self::assertSame([], Job::stale([], $report));
    }
}
