<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Support\Conformance;

use Carbon\CarbonImmutable;
use Lezhnev74\Jobs\Model\Job;
use Lezhnev74\Jobs\Model\NewJob;
use Lezhnev74\Jobs\Model\Reason;
use Lezhnev74\Jobs\Model\RetryState;
use Lezhnev74\Jobs\Model\Settlement;
use Lezhnev74\Jobs\Model\Telemetry;
use Lezhnev74\Jobs\Model\TerminalKind;
use Lezhnev74\Jobs\Tests\Support\DriverConformanceTestCase;

/** @phpstan-require-extends DriverConformanceTestCase */
trait SettleConformance
{
    public function testCompleteSettlement(): void
    {
        [$id] = $this->pushClaimable(1);
        $claimed = $this->claim(worker: 'w1');

        $report = $this->queue()->settle($claimed->token, Settlement::complete($claimed->jobs[0]));
        $job = $this->getJob($id);

        self::assertTrue($report->isAcked($id));
        self::assertNotNull($job->termination);
        self::assertSame(TerminalKind::Completed, $job->termination->kind);
        self::assertTrue($job->termination->at->greaterThanOrEqualTo($job->createdAt));
        self::assertUnleased($job, 'w1');
        self::assertSame([1, 0, 0, null, null], self::retryTuple($job));
        self::assertTrue($job->availableAt->equalTo($claimed->jobs[0]->availableAt));
        self::assertTrue($this->claim(worker: 'w2')->isEmpty(), 'terminal rows are never claimable');
    }

    public function testDiscardSettlement(): void
    {
        [$id] = $this->pushClaimable(1);
        $claimed = $this->claim(worker: 'w1');

        $report = $this->queue()->settle($claimed->token, Settlement::discard($claimed->jobs[0], new Reason(['code' => 'poison'])));
        $job = $this->getJob($id);

        self::assertTrue($report->isAcked($id));
        self::assertNotNull($job->termination);
        self::assertSame(TerminalKind::Discarded, $job->termination->kind);
        self::assertUnleased($job, 'w1');
        self::assertSame([1, 0, 0, ['code' => 'poison'], null], self::retryTuple($job));
        self::assertTrue($this->claim(worker: 'w2')->isEmpty());
    }

    public function testFailSettlement(): void
    {
        [$id] = $this->pushClaimable(1);
        $claimed = $this->claim(worker: 'w1');
        $retryAt = CarbonImmutable::now()->addHour()->setMicrosecond(0);

        $report = $this->queue()->settle($claimed->token, Settlement::fail($claimed->jobs[0], new Reason(['code' => 'timeout']), $retryAt));
        $job = $this->getJob($id);

        self::assertTrue($report->isAcked($id));
        self::assertNull($job->termination);
        self::assertUnleased($job, 'w1');
        self::assertTrue($job->availableAt->equalTo($retryAt), 'requeued at the worker-decided time');
        self::assertSame([1, 1, 0, ['code' => 'timeout'], null], self::retryTuple($job));
        self::assertTrue($this->claim(worker: 'w2')->isEmpty(), 'scheduled in the future');
    }

    public function testRescheduleSettlement(): void
    {
        [$id] = $this->pushClaimable(1);
        $claimed = $this->claim(worker: 'w1');
        $again = CarbonImmutable::now()->subMinute()->setMicrosecond(0);

        $report = $this->queue()->settle($claimed->token, Settlement::reschedule($claimed->jobs[0], new Reason(['code' => 'recur']), $again));
        $job = $this->getJob($id);

        self::assertTrue($report->isAcked($id));
        self::assertNull($job->termination);
        self::assertUnleased($job, 'w1');
        self::assertTrue($job->availableAt->equalTo($again));
        self::assertSame([1, 0, 1, null, ['code' => 'recur']], self::retryTuple($job));
        self::assertSameIds([$id], $this->claim(worker: 'w2')->jobs, 'claimable again right away');
    }

    public function testReleaseSettlement(): void
    {
        [$id] = $this->pushClaimable(1);
        $claimed = $this->claim(worker: 'w1');

        $report = $this->queue()->settle($claimed->token, Settlement::release($claimed->jobs[0]));
        $job = $this->getJob($id);

        self::assertTrue($report->isAcked($id));
        self::assertNull($job->termination);
        self::assertUnleased($job, 'w1');
        self::assertTrue($job->availableAt->equalTo($claimed->jobs[0]->availableAt), 'available_at untouched');
        self::assertSame([1, 0, 0, null, null], self::retryTuple($job));
        self::assertTrue($job->updatedAt->greaterThan($claimed->jobs[0]->updatedAt));
    }

    public function testStreakCountersResetEachOther(): void
    {
        [$id] = $this->pushClaimable(1);
        $why = new Reason(['code' => 'x']);
        $past = CarbonImmutable::now()->subMinute();

        $c1 = $this->claim();
        $this->queue()->settle($c1->token, Settlement::fail($c1->jobs[0], $why, $past));
        $c2 = $this->claim();
        $this->queue()->settle($c2->token, Settlement::fail($c2->jobs[0], $why, $past));
        self::assertSame([2, 2, 0], \array_slice(self::retryTuple($this->getJob($id)), 0, 3));

        $c3 = $this->claim();
        $this->queue()->settle($c3->token, Settlement::reschedule($c3->jobs[0], $why, $past));
        self::assertSame([3, 0, 1], \array_slice(self::retryTuple($this->getJob($id)), 0, 3), 'reschedule resets the failure streak');

        $c4 = $this->claim();
        $this->queue()->settle($c4->token, Settlement::fail($c4->jobs[0], $why, $past));
        self::assertSame([4, 1, 0], \array_slice(self::retryTuple($this->getJob($id)), 0, 3), 'fail resets the reschedule streak');
    }

    public function testReleaseLeavesTheStreaksAlone(): void
    {
        [$id] = $this->pushClaimable(1);
        $c1 = $this->claim();
        $this->queue()->settle($c1->token, Settlement::fail($c1->jobs[0], new Reason(['code' => 'x']), CarbonImmutable::now()->subMinute()));

        $c2 = $this->claim();
        $this->queue()->settle($c2->token, Settlement::release($c2->jobs[0]));

        self::assertSame([2, 1, 0, ['code' => 'x'], null], self::retryTuple($this->getJob($id)), 'a give-back is not a settlement of the cycle');
    }

    public function testTerminalSettlementFreezesTheStreakCounters(): void
    {
        [$id] = $this->pushClaimable(1);
        $c1 = $this->claim();
        $this->queue()->settle($c1->token, Settlement::fail($c1->jobs[0], new Reason(['code' => 'x']), CarbonImmutable::now()->subMinute()));

        $c2 = $this->claim();
        $this->queue()->settle($c2->token, Settlement::complete($c2->jobs[0]));

        self::assertSame([2, 1, 0, ['code' => 'x'], null], self::retryTuple($this->getJob($id)), 'history stays readable');
    }

    public function testDiscardKeepsTheStreaksAndOverwritesTheFailReason(): void
    {
        [$id] = $this->pushClaimable(1);
        $past = CarbonImmutable::now()->subMinute();
        $c1 = $this->claim();
        $this->queue()->settle($c1->token, Settlement::reschedule($c1->jobs[0], new Reason(['code' => 'r']), $past));
        $c2 = $this->claim();
        $this->queue()->settle($c2->token, Settlement::fail($c2->jobs[0], new Reason(['code' => 'f1']), $past));

        $c3 = $this->claim();
        $this->queue()->settle($c3->token, Settlement::discard($c3->jobs[0], new Reason(['code' => 'f2'])));

        self::assertSame([3, 1, 0, ['code' => 'f2'], ['code' => 'r']], self::retryTuple($this->getJob($id)));
    }

    public function testReasonsHoldTheLatestCycleOnly(): void
    {
        [$id] = $this->pushClaimable(1);
        $past = CarbonImmutable::now()->subMinute();
        $c1 = $this->claim();
        $this->queue()->settle($c1->token, Settlement::fail($c1->jobs[0], new Reason(['code' => 'first', 'detail' => ['n' => 1]]), $past));

        $c2 = $this->claim();
        $this->queue()->settle($c2->token, Settlement::fail($c2->jobs[0], new Reason(['code' => 'second']), $past));

        self::assertSame(['code' => 'second'], $this->getJob($id)->retry->lastFailReason, 'overwritten, not merged');
    }

    public function testMixedBatchAppliesPerRowInputs(): void
    {
        [$a, $b, $c, $d] = $this->pushClaimable(4);
        $claimed = $this->claim(limit: 4);
        $atB = CarbonImmutable::now()->addMinutes(2)->setMicrosecond(0);
        $atC = CarbonImmutable::now()->addMinutes(3)->setMicrosecond(0);

        $report = $this->queue()->settle(
            $claimed->token,
            Settlement::discard($claimed->jobs[0], new Reason(['code' => 'a'])),
            Settlement::fail($claimed->jobs[1], new Reason(['code' => 'b']), $atB),
            Settlement::reschedule($claimed->jobs[2], new Reason(['code' => 'c']), $atC),
            Settlement::complete($claimed->jobs[3]),
        );

        self::assertTrue($report->isClean());
        $jobA = $this->getJob($a);
        self::assertSame(TerminalKind::Discarded, $jobA->termination?->kind);
        self::assertSame(['code' => 'a'], $jobA->retry->lastFailReason);
        $jobB = $this->getJob($b);
        self::assertTrue($jobB->availableAt->equalTo($atB));
        self::assertSame([1, 1, 0, ['code' => 'b'], null], self::retryTuple($jobB));
        $jobC = $this->getJob($c);
        self::assertTrue($jobC->availableAt->equalTo($atC));
        self::assertSame([1, 0, 1, null, ['code' => 'c']], self::retryTuple($jobC));
        self::assertSame(TerminalKind::Completed, $this->getJob($d)->termination?->kind);
    }

    public function testMixedBatchResetsStreaksLikeUniformOnes(): void
    {
        [$a, $b] = $this->pushClaimable(2);
        $past = CarbonImmutable::now()->subMinute();
        $c1 = $this->claim(limit: 2);
        $this->queue()->settle($c1->token, Settlement::fail($c1->jobs[0], new Reason(['code' => 'x']), $past), Settlement::fail($c1->jobs[1], new Reason(['code' => 'x']), $past));

        $c2 = $this->claim(limit: 2);
        $this->queue()->settle($c2->token, Settlement::reschedule($c2->jobs[0], new Reason(['code' => 'r']), $past), Settlement::release($c2->jobs[1]));

        self::assertSame([2, 0, 1, ['code' => 'x'], ['code' => 'r']], self::retryTuple($this->getJob($a)), 'reschedule resets failures in a mixed batch');
        self::assertSame([2, 1, 0, ['code' => 'x'], null], self::retryTuple($this->getJob($b)), 'release touches nothing in a mixed batch');
    }

    public function testUniformBatchWithDifferentInputsPerRow(): void
    {
        [$a, $b] = $this->pushClaimable(2);
        $claimed = $this->claim(limit: 2);
        $atA = CarbonImmutable::now()->addMinutes(1)->setMicrosecond(0);
        $atB = CarbonImmutable::now()->addMinutes(2)->setMicrosecond(0);

        $report = $this->queue()->settle(
            $claimed->token,
            Settlement::fail($claimed->jobs[0], new Reason(['code' => 'a']), $atA),
            Settlement::fail($claimed->jobs[1], new Reason(['code' => 'b']), $atB),
        );

        self::assertTrue($report->isClean());
        self::assertTrue($this->getJob($a)->availableAt->equalTo($atA));
        self::assertSame(['code' => 'a'], $this->getJob($a)->retry->lastFailReason);
        self::assertTrue($this->getJob($b)->availableAt->equalTo($atB));
        self::assertSame(['code' => 'b'], $this->getJob($b)->retry->lastFailReason);
    }

    public function testSettleOfAnEmptyBatchOrUnknownJobsNeverThrows(): void
    {
        $claimed = $this->claim();
        $ghost = self::unstoredJob($this->newJob());

        self::assertTrue($this->queue()->settle($claimed->token)->isClean());
        $report = $this->queue()->settle($claimed->token, Settlement::complete($ghost));

        self::assertTrue($report->isStale($ghost->id), 'a job that no longer exists is reported stale');
        self::assertNull($this->monitor()->get($ghost->id));
    }

    /** A `Job` snapshot with no row behind it - what a worker holds after its job was deleted. */
    private static function unstoredJob(NewJob $new): Job
    {
        $now = CarbonImmutable::now();

        return new Job(
            id: $new->id,
            name: $new->name,
            pool: $new->pool,
            payload: $new->payload,
            dedupKey: $new->dedupKey,
            availableAt: $now,
            termination: null,
            lease: null,
            retry: RetryState::fresh(),
            telemetry: Telemetry::none(),
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
