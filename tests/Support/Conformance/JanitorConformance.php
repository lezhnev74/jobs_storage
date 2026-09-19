<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Support\Conformance;

use Carbon\CarbonImmutable;
use Lezhnev74\Jobs\Model\Reason;
use Lezhnev74\Jobs\Model\Settlement;
use Lezhnev74\Jobs\Query\State;
use Lezhnev74\Jobs\Tests\Support\DriverConformanceTestCase;

/** @phpstan-require-extends DriverConformanceTestCase */
trait JanitorConformance
{
    public function testDeleteSkipsLiveLeasedRows(): void
    {
        [$leased, $b, $c] = $this->pushClaimable(3);
        $claimed = $this->claim(limit: 1, worker: 'w1');

        self::assertSame(2, $this->monitor()->delete($this->poolQuery()));

        self::assertSameIds([$leased], $this->monitor()->read($this->poolQuery()));
        self::assertNull($this->monitor()->get($b));
        self::assertNull($this->monitor()->get($c));
        self::assertTrue($this->queue()->settle($claimed->token, Settlement::complete($claimed->jobs[0]))->isAcked($leased), 'the holder is unaffected');
    }

    public function testDeleteRemovesRowsWhoseLeaseExpired(): void
    {
        [$id] = $this->pushClaimable(1);
        $this->claim(worker: 'w1', ttl: $this->minTtl());
        self::assertSame(0, $this->monitor()->delete($this->poolQuery()));

        $this->waitOutLease();

        self::assertSame(1, $this->monitor()->delete($this->poolQuery()), 'an expired lease is not live');
        self::assertNull($this->monitor()->get($id));
    }

    public function testDeleteIncludingLeasedRemovesLiveLeasedRowsAndFencesTheHolder(): void
    {
        [$leased, $b] = $this->pushClaimable(2);
        $claimed = $this->claim(limit: 1, worker: 'w1');

        self::assertSame(2, $this->monitor()->deleteIncludingLeased($this->poolQuery()));

        self::assertNull($this->monitor()->get($leased));
        self::assertNull($this->monitor()->get($b));
        $report = $this->queue()->settle($claimed->token, Settlement::complete($claimed->jobs[0]));
        self::assertTrue($report->isStale($leased), 'the holder\'s ack becomes stale');
        self::assertNull($this->monitor()->get($leased), 'and the telemetry write matches nothing');
    }

    public function testDeleteHonorsFiltersAndLimitSoCallersCanLoop(): void
    {
        $this->pushClaimable(5);
        $this->pushClaimable(1, pool: 'other');
        $claimed = $this->claim(limit: 2, worker: 'w1');
        $this->queue()->settle($claimed->token, Settlement::complete($claimed->jobs[0]), Settlement::discard($claimed->jobs[1], new Reason(['code' => 'x'])));
        $terminal = $this->poolQuery()->states(State::Completed, State::Discarded)->limit(1);

        self::assertSame(1, $this->monitor()->delete($terminal));
        self::assertSame(1, $this->monitor()->delete($terminal));
        self::assertSame(0, $this->monitor()->delete($terminal));

        self::assertSame(3, $this->monitor()->count($this->poolQuery()));
        self::assertSame(1, $this->monitor()->count($this->poolQuery('other')));
        self::assertSame(2, $this->monitor()->deleteIncludingLeased($this->poolQuery()->limit(2)));
        self::assertSame(1, $this->monitor()->count($this->poolQuery()));
    }

    public function testRequeueRearmsAnyJobClearsLeaseAndTerminalMarkersAndKeepsCounters(): void
    {
        $this->pushClaimable(3);
        $scheduled = $this->newJob(availableAt: CarbonImmutable::now()->addHour());
        $this->queue()->push($scheduled);
        $claimed = $this->claim(limit: 3, worker: 'w1');
        $this->queue()->settle(
            $claimed->token,
            Settlement::complete($claimed->jobs[0]),
            Settlement::discard($claimed->jobs[1], new Reason(['code' => 'poison'])),
        );
        $at = CarbonImmutable::now()->subMinute()->setMicrosecond(0);
        $why = new Reason(['code' => 'ops', 'ticket' => 42]);

        self::assertSame(4, $this->monitor()->requeue($this->poolQuery(), $at, $why));

        foreach ([...$claimed->ids(), $scheduled->id] as $id) {
            $job = $this->getJob($id);
            self::assertNull($job->termination, 'terminal markers cleared');
            self::assertNull($job->lease, 'lease cleared');
            self::assertTrue($job->availableAt->equalTo($at));
            self::assertSame($why->data, $job->retry->lastRescheduleReason);
            self::assertSame(0, $job->retry->consecutiveReschedules, 'counters are left alone');
        }
        self::assertSame(1, $this->getJob($claimed->jobs[2]->id)->retry->attempts);
        self::assertSame(['code' => 'poison'], $this->getJob($claimed->jobs[1]->id)->retry->lastFailReason, 'fail history stays');
        self::assertSame('w1', $this->getJob($claimed->jobs[2]->id)->telemetry->lastClaimedBy);
        self::assertSame(4, $this->monitor()->count($this->poolQuery()->states(State::Claimable)));
        self::assertCount(4, $this->claim(limit: 10, worker: 'w2'));
    }

    public function testRequeueFencesTheLeaseHolder(): void
    {
        [$id] = $this->pushClaimable(1);
        $claimed = $this->claim(worker: 'w1');

        self::assertSame(1, $this->monitor()->requeue($this->poolQuery(), CarbonImmutable::now()->addHour(), new Reason(['code' => 'ops'])));
        $report = $this->queue()->settle($claimed->token, Settlement::complete($claimed->jobs[0]));

        self::assertTrue($report->isStale($id));
        self::assertNull($this->getJob($id)->termination);
        self::assertSame(1, $this->getJob($id)->telemetry->staleAck?->count, 'a terminating late ack is counted');
        self::assertSame(1, $this->monitor()->count($this->poolQuery()->states(State::Scheduled)));
    }

    public function testRequeueHonorsStateFilterAndLimit(): void
    {
        $this->pushClaimable(3);
        $claimed = $this->claim(limit: 3, worker: 'w1');
        $why = new Reason(['code' => 'x']);
        $this->queue()->settle(
            $claimed->token,
            Settlement::discard($claimed->jobs[0], $why),
            Settlement::discard($claimed->jobs[1], $why),
            Settlement::complete($claimed->jobs[2]),
        );
        $discarded = $this->poolQuery()->states(State::Discarded)->limit(1);
        $at = CarbonImmutable::now()->subMinute();

        self::assertSame(1, $this->monitor()->requeue($discarded, $at, $why));
        self::assertSame(1, $this->monitor()->requeue($discarded, $at, $why));
        self::assertSame(0, $this->monitor()->requeue($discarded, $at, $why));

        self::assertSame(2, $this->monitor()->count($this->poolQuery()->states(State::Claimable)));
        self::assertSame(1, $this->monitor()->count($this->poolQuery()->states(State::Completed)), 'not matched, not touched');
    }

    public function testJanitorVerbsOnAnEmptyMatchReturnZero(): void
    {
        $this->pushClaimable(1, pool: 'other');

        self::assertSame(0, $this->monitor()->delete($this->poolQuery()));
        self::assertSame(0, $this->monitor()->deleteIncludingLeased($this->poolQuery()));
        self::assertSame(0, $this->monitor()->requeue($this->poolQuery(), CarbonImmutable::now(), new Reason([])));
        self::assertSame(1, $this->monitor()->count($this->poolQuery('other')));
    }
}
