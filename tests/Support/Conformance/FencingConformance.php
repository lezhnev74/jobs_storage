<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Support\Conformance;

use Carbon\CarbonImmutable;
use Lezhnev74\Jobs\Model\Reason;
use Lezhnev74\Jobs\Model\Settlement;
use Lezhnev74\Jobs\Model\TerminalKind;
use Lezhnev74\Jobs\Tests\Support\DriverConformanceTestCase;

/** @phpstan-require-extends DriverConformanceTestCase */
trait FencingConformance
{
    public function testLateAckAfterExpiryLandsWhenNobodyReclaimed(): void
    {
        [$id] = $this->pushClaimable(1);
        $claimed = $this->claim(worker: 'w1', ttl: $this->minTtl());
        $this->waitOutLease();

        $report = $this->queue()->settle($claimed->token, Settlement::complete($claimed->jobs[0]));
        $job = $this->getJob($id);

        self::assertTrue($report->isAcked($id), 'expiry alone invalidates nothing');
        self::assertNotNull($job->termination);
        self::assertNull($job->telemetry->staleAck);
    }

    public function testStaleSettleAfterReclaimIsReportedAndCounted(): void
    {
        [$id] = $this->pushClaimable(1);
        $first = $this->claim(worker: 'w1', ttl: $this->minTtl());
        $this->waitOutLease();
        $second = $this->claim(worker: 'w2');

        $stale = $this->queue()->settle($first->token, Settlement::complete($first->jobs[0]));
        $job = $this->getJob($id);

        self::assertSame([], $stale->acked);
        self::assertTrue($stale->isStale($id));
        self::assertNull($job->termination, 'the fenced-out ack changed nothing');
        self::assertNotNull($job->lease);
        self::assertSame('w2', $job->lease->by, 'the legitimate owner keeps the lease');
        self::assertNotNull($job->telemetry->staleAck);
        self::assertSame(1, $job->telemetry->staleAck->count);
        self::assertSame('w1', $job->telemetry->staleAck->by);

        self::assertTrue($this->queue()->settle($second->token, Settlement::complete($second->jobs[0]))->isAcked($id));
        self::assertNotNull($this->getJob($id)->termination);
    }

    public function testStaleAckCountAccumulatesAcrossOutcomesAndWorkers(): void
    {
        [$id] = $this->pushClaimable(1);
        $first = $this->claim(worker: 'w1', ttl: $this->minTtl());
        $this->waitOutLease();
        $second = $this->claim(worker: 'w2', ttl: $this->minTtl());
        $this->waitOutLease();
        $this->claim(worker: 'w3');

        $this->queue()->settle($first->token, Settlement::fail($first->jobs[0], new Reason(['code' => 'x']), CarbonImmutable::now()));
        $this->queue()->settle($second->token, Settlement::discard($second->jobs[0], new Reason(['code' => 'y'])));
        $job = $this->getJob($id);

        self::assertNotNull($job->telemetry->staleAck);
        self::assertSame(2, $job->telemetry->staleAck->count);
        self::assertSame('w2', $job->telemetry->staleAck->by, 'the latest late reporter');
        self::assertSame([3, 0, 0, null, null], self::retryTuple($job), 'fenced-out acks change no state');
        self::assertSame('w3', $job->lease?->by);
    }

    public function testStaleReleaseIsReportedButNotCounted(): void
    {
        [$id] = $this->pushClaimable(1);
        $first = $this->claim(worker: 'w1', ttl: $this->minTtl());
        $this->waitOutLease();
        $this->claim(worker: 'w2');

        $report = $this->queue()->settle($first->token, Settlement::release($first->jobs[0]));
        $job = $this->getJob($id);

        self::assertTrue($report->isStale($id));
        self::assertNull($job->telemetry->staleAck, 'a stale release is not counted');
        self::assertNotNull($job->lease);
        self::assertSame('w2', $job->lease->by);
    }

    public function testASettledJobFencesItsOwnTokenOut(): void
    {
        [$id] = $this->pushClaimable(1);
        $claimed = $this->claim(worker: 'w1');
        $this->queue()->settle($claimed->token, Settlement::complete($claimed->jobs[0]));

        $again = $this->queue()->settle($claimed->token, Settlement::discard($claimed->jobs[0], new Reason(['code' => 'late'])));
        $job = $this->getJob($id);

        self::assertTrue($again->isStale($id), 'settlement clears the token');
        self::assertSame(TerminalKind::Completed, $job->termination?->kind, 'the first outcome stands');
        self::assertNull($job->retry->lastFailReason);
        self::assertSame(1, $job->telemetry->staleAck?->count);
    }

    public function testTheWorkerIdIsNotTheToken(): void
    {
        [$a, $b] = $this->pushClaimable(2);
        $first = $this->claim(limit: 1, worker: 'w1');
        $second = $this->claim(limit: 1, worker: 'w1');

        $crossed = $this->queue()->settle($second->token, Settlement::complete($first->jobs[0]));

        self::assertTrue($crossed->isStale($a), 'same worker, different claim event: fenced');
        self::assertNull($this->getJob($a)->termination);
        self::assertSame(1, $this->getJob($a)->telemetry->staleAck?->count);
        self::assertTrue($this->queue()->settle($second->token, Settlement::complete($second->jobs[0]))->isAcked($b));
    }

    public function testMixedBatchWithPartialStalenessNeverThrows(): void
    {
        [$a, $b, $c] = $this->pushClaimable(3);
        $first = $this->claim(limit: 3, worker: 'w1', ttl: $this->minTtl());
        $this->waitOutLease();
        $reclaim = $this->claim(limit: 1, worker: 'w2');
        self::assertSameIds([$a], $reclaim->jobs, 'the oldest job moves to w2');
        $retryAt = CarbonImmutable::now()->addHour()->setMicrosecond(0);

        $report = $this->queue()->settle(
            $first->token,
            Settlement::complete($first->jobs[0]),
            Settlement::fail($first->jobs[1], new Reason(['code' => 'x']), $retryAt),
            Settlement::release($first->jobs[2]),
        );

        self::assertTrue($report->isStale($a));
        self::assertTrue($report->isAcked($b));
        self::assertTrue($report->isAcked($c));
        $jobA = $this->getJob($a);
        self::assertNull($jobA->termination);
        self::assertNotNull($jobA->telemetry->staleAck);
        self::assertSame('w1', $jobA->telemetry->staleAck->by);
        self::assertNotNull($jobA->lease);
        $jobB = $this->getJob($b);
        self::assertUnleased($jobB, 'w1');
        self::assertTrue($jobB->availableAt->equalTo($retryAt));
        self::assertSame([1, 1, 0, ['code' => 'x'], null], self::retryTuple($jobB));
        $jobC = $this->getJob($c);
        self::assertUnleased($jobC, 'w1');
        self::assertTrue($jobC->availableAt->equalTo($first->jobs[2]->availableAt));
    }

    public function testUniformBatchWithPartialStalenessCountsOnlyTheFencedRows(): void
    {
        [$a, $b] = $this->pushClaimable(2);
        $first = $this->claim(limit: 2, worker: 'w1', ttl: $this->minTtl());
        $this->waitOutLease();
        $this->claim(limit: 1, worker: 'w2');

        $report = $this->queue()->settle($first->token, Settlement::complete($first->jobs[0]), Settlement::complete($first->jobs[1]));

        self::assertTrue($report->isStale($a));
        self::assertTrue($report->isAcked($b));
        self::assertSame(1, $this->getJob($a)->telemetry->staleAck?->count);
        self::assertNull($this->getJob($b)->telemetry->staleAck);
        self::assertNotNull($this->getJob($b)->termination);
    }
}
