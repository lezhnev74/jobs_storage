<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Support\Conformance;

use Carbon\CarbonImmutable;
use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Model\PoolName;
use Lezhnev74\Jobs\Model\Reason;
use Lezhnev74\Jobs\Model\Settlement;
use Lezhnev74\Jobs\Query\ClaimQuery;
use Lezhnev74\Jobs\Query\GroupBy;
use Lezhnev74\Jobs\Query\JobQuery;
use Lezhnev74\Jobs\Query\Order;
use Lezhnev74\Jobs\Query\State;
use Lezhnev74\Jobs\Tests\Support\DriverConformanceTestCase;

/** @phpstan-require-extends DriverConformanceTestCase */
trait ReaderConformance
{
    public function testReadDefaultsToCreationOrderAndHonorsEveryOrder(): void
    {
        $now = CarbonImmutable::now();
        $first = $this->newJob(availableAt: $now->subMinutes(10));
        $second = $this->newJob(availableAt: $now->subMinutes(30));
        $third = $this->newJob(availableAt: $now->subMinutes(20));
        $this->queue()->push($first, $second, $third);
        $byCreation = [$first->id, $second->id, $third->id];
        $byAvailability = [$second->id, $third->id, $first->id];

        self::assertSameIds($byCreation, $this->monitor()->read($this->poolQuery()), 'default: id ascending = creation order');
        self::assertSameIds($byCreation, $this->monitor()->read($this->poolQuery()->orderBy(Order::IdAsc)));
        self::assertSameIds(array_reverse($byCreation), $this->monitor()->read($this->poolQuery()->orderBy(Order::IdDesc)));
        self::assertSameIds($byAvailability, $this->monitor()->read($this->poolQuery()->orderBy(Order::AvailableAtAsc)));
        self::assertSameIds(array_reverse($byAvailability), $this->monitor()->read($this->poolQuery()->orderBy(Order::AvailableAtDesc)));
    }

    public function testReadHonorsTheLimitAndCountIgnoresIt(): void
    {
        $ids = $this->pushClaimable(5);
        $q = $this->poolQuery()->limit(2);

        self::assertSameIds([$ids[0], $ids[1]], $this->monitor()->read($q));
        self::assertSameIds([$ids[4], $ids[3]], $this->monitor()->read($q->orderBy(Order::AvailableAtDesc)));
        self::assertSame(5, $this->monitor()->count($q));
    }

    public function testKeysetPagingWalksTheWholeSetExactlyOnce(): void
    {
        $ids = $this->pushClaimable(7);
        $page = $this->poolQuery()->limit(3);

        $walked = [];
        $cursor = null;
        // A dashboard's loop: read a page, resume past its last id, stop on a short page.
        do {
            $jobs = [...$this->monitor()->read($cursor === null ? $page : $page->after($cursor))];
            $walked = [...$walked, ...$jobs];
            $cursor = $jobs === [] ? null : $jobs[\count($jobs) - 1]->id;
        } while (\count($jobs) === 3);

        self::assertSameIds($ids, $walked, 'every row once, in creation order, no gaps or repeats');
    }

    public function testADescendingWalkPagesTheOtherWay(): void
    {
        $ids = $this->pushClaimable(4);
        $q = $this->poolQuery()->orderBy(Order::IdDesc)->limit(2);

        $first = [...$this->monitor()->read($q)];
        $second = $this->monitor()->read($q->after($first[1]->id));

        self::assertSameIds([$ids[3], $ids[2]], $first);
        self::assertSameIds([$ids[1], $ids[0]], $second);
    }

    public function testTheCursorIsExclusiveAndIgnoredByCountAndAggregate(): void
    {
        $ids = $this->pushClaimable(3);
        $q = $this->poolQuery()->after($ids[0]);

        self::assertSameIds([$ids[1], $ids[2]], $this->monitor()->read($q), 'the cursor row itself is excluded');
        self::assertSame(3, $this->monitor()->count($q));
        self::assertSame([['group' => 'emails', 'count' => 3]], $this->monitor()->aggregate($q, GroupBy::Pool));
    }

    public function testACursorPastTheLastRowReadsNothing(): void
    {
        $ids = $this->pushClaimable(2);

        self::assertSameIds([], $this->monitor()->read($this->poolQuery()->after($ids[1])));
    }

    public function testReadReturnsTheFullRecordLikeGet(): void
    {
        [$id] = $this->pushClaimable(1);
        $claimed = $this->claim(worker: 'w1');
        $this->queue()->settle($claimed->token, Settlement::fail($claimed->jobs[0], new Reason(['code' => 'x']), CarbonImmutable::now()->addHour()));

        $read = [...$this->monitor()->read($this->poolQuery())];

        self::assertCount(1, $read);
        self::assertEquals($this->getJob($id), $read[0]);
        self::assertSame('w1', $read[0]->telemetry->lastClaimedBy);
    }

    public function testReadAndCountNarrowByTheClaimCriteriaByNamePrefixAndPayload(): void
    {
        $now = CarbonImmutable::now();
        // Each fixture matches exactly one criterion, so an AND that reads nothing proves both were applied.
        $billing = $this->newJob(name: 'billing.invoice', payload: ['customer' => ['region' => 'eu'], 'tier' => 'gold'], availableAt: $now);
        $mail = $this->newJob(name: 'mail.send', payload: ['customer' => ['region' => 'us'], 'tier' => 'gold'], availableAt: $now);
        $silver = $this->newJob(name: 'mail.send', payload: ['customer' => ['region' => 'eu'], 'tier' => 'silver'], availableAt: $now);
        $this->queue()->push($billing, $mail, $silver, $this->newJob(pool: 'other'));
        $base = ClaimQuery::pool(new PoolName('emails'));

        self::assertSameIds([$billing->id, $mail->id, $silver->id], $this->monitor()->read(JobQuery::from($base)));
        self::assertSameIds([$billing->id], $this->monitor()->read(JobQuery::from($base->namePrefix('billing.'))));
        self::assertSameIds([$mail->id], $this->monitor()->read(JobQuery::from($base->payload('$.customer.region', 'us'))));
        self::assertSameIds([$silver->id], $this->monitor()->read(JobQuery::from($base->payload('$.tier', 'silver'))));
        self::assertSame(0, $this->monitor()->count(JobQuery::from($base->namePrefix('billing.')->payload('$.customer.region', 'us'))));
        self::assertSame(0, $this->monitor()->count(JobQuery::from($base->payload('$.customer.region', 'us')->payload('$.tier', 'silver'))));
    }

    public function testEachStateFilterMatchesExactlyItsJob(): void
    {
        $expected = $this->seedOneJobPerState();

        foreach (State::cases() as $state) {
            $q = $this->poolQuery()->states($state);
            $jobs = [...$this->monitor()->read($q)];

            self::assertSameIds([$expected[$state->value]], $jobs, $state->value);
            self::assertSame(1, $this->monitor()->count($q), $state->value);
            self::assertSame($state, $jobs[0]->state(), 'Job::state() agrees with the database');
        }
    }

    public function testStateFiltersAccumulateAndAnEmptyFilterMeansAnyState(): void
    {
        $expected = $this->seedOneJobPerState();

        self::assertSame(5, $this->monitor()->count($this->poolQuery()));
        self::assertSame(2, $this->monitor()->count($this->poolQuery()->states(State::Completed, State::Discarded)));
        self::assertSame(3, $this->monitor()->count($this->poolQuery()->states(State::Claimable)->states(State::Scheduled, State::Claimed)));
        self::assertSameIds(
            [$expected['claimable'], $expected['scheduled']],
            $this->monitor()->read($this->poolQuery()->states(State::Scheduled, State::Claimable)->orderBy(Order::AvailableAtAsc)),
        );
    }

    public function testAnExpiredLeaseReadsAsClaimableNotClaimed(): void
    {
        [$id] = $this->pushClaimable(1);
        $this->claim(worker: 'w1', ttl: $this->minTtl());
        self::assertSame(1, $this->monitor()->count($this->poolQuery()->states(State::Claimed)));

        $this->waitOutLease();

        self::assertSame(0, $this->monitor()->count($this->poolQuery()->states(State::Claimed)));
        self::assertSameIds([$id], $this->monitor()->read($this->poolQuery()->states(State::Claimable)));
        self::assertSame(0, $this->monitor()->count($this->poolQuery()->states(State::Scheduled)));
    }

    public function testAvailableWindowIsInclusiveBeforeAndExclusiveAfter(): void
    {
        $now = CarbonImmutable::now()->setMicrosecond(0);
        $oldest = $this->newJob(availableAt: $now->subHours(2));
        $middle = $this->newJob(availableAt: $now->subHour());
        $newest = $this->newJob(availableAt: $now);
        $this->queue()->push($oldest, $middle, $newest);

        self::assertSameIds([$oldest->id, $middle->id], $this->monitor()->read($this->poolQuery()->availableBefore($now->subHour())), '<= cutoff');
        self::assertSameIds([$newest->id], $this->monitor()->read($this->poolQuery()->availableAfter($now->subHour())), '> cutoff');
        self::assertSameIds([$middle->id], $this->monitor()->read($this->poolQuery()->availableAfter($now->subHours(2))->availableBefore($now->subHour())));
        self::assertSame(1, $this->monitor()->count($this->poolQuery()->states(State::Claimable)->availableBefore($now->subMinutes(90))));
    }

    public function testAggregateByStateCountsEveryInferredState(): void
    {
        $this->seedOneJobPerState();
        $this->pushClaimable(1, pool: 'other');

        $groups = $this->monitor()->aggregate($this->poolQuery(), GroupBy::State);

        self::assertSame(
            ['claimable' => 1, 'claimed' => 1, 'completed' => 1, 'discarded' => 1, 'scheduled' => 1],
            self::groupMap($groups),
        );
    }

    public function testAggregateByPoolAndNamePrefix(): void
    {
        $now = CarbonImmutable::now();
        $this->queue()->push(
            $this->newJob(name: 'billing.invoice.send', availableAt: $now),
            $this->newJob(name: 'billing.refund', availableAt: $now),
            $this->newJob(name: 'mail', availableAt: $now),
            $this->newJob(name: 'billing.other', pool: 'other', availableAt: $now),
        );

        self::assertSame(['emails' => 3], self::groupMap($this->monitor()->aggregate($this->poolQuery(), GroupBy::Pool)));
        self::assertSame(['billing' => 2, 'mail' => 1], self::groupMap($this->monitor()->aggregate($this->poolQuery(), GroupBy::NamePrefix)));
        self::assertSame(['billing' => 1], self::groupMap($this->monitor()->aggregate($this->poolQuery('other'), GroupBy::NamePrefix)));
    }

    public function testAggregateByReasonCodeAndWorkerReportNullForRowsWithoutAValue(): void
    {
        $this->pushClaimable(4);
        $past = CarbonImmutable::now()->subMinute();
        $w1 = $this->claim(limit: 2, worker: 'w1');
        $this->queue()->settle(
            $w1->token,
            Settlement::fail($w1->jobs[0], new Reason(['code' => 'timeout']), $past),
            Settlement::discard($w1->jobs[1], new Reason(['code' => 'timeout'])),
        );
        $w2 = $this->claim(limit: 1, worker: 'w2');
        $this->queue()->settle($w2->token, Settlement::fail($w2->jobs[0], new Reason(['code' => 'boom', 'detail' => 1]), $past));

        self::assertSame(['' => 1, 'boom' => 1, 'timeout' => 2], self::groupMap($this->monitor()->aggregate($this->poolQuery(), GroupBy::ReasonCode)));
        self::assertSame(['' => 1, 'w1' => 2, 'w2' => 1], self::groupMap($this->monitor()->aggregate($this->poolQuery(), GroupBy::Worker)), 'consumed_by survives settlement');
        self::assertSame(['' => 1, 'w1' => 1, 'w2' => 1], self::groupMap($this->monitor()->aggregate($this->poolQuery()->states(State::Claimable), GroupBy::Worker)), 'the filter applies');
    }

    public function testAggregateIgnoresOrderAndLimitAndReturnsNothingForNoMatch(): void
    {
        $this->pushClaimable(3);

        self::assertSame(['claimable' => 3], self::groupMap($this->monitor()->aggregate($this->poolQuery()->limit(1)->orderBy(Order::IdDesc), GroupBy::State)));
        self::assertSame([], $this->monitor()->aggregate($this->poolQuery('other'), GroupBy::State));
    }

    /** @return array<string, JobId> */
    private function seedOneJobPerState(): array
    {
        $now = CarbonImmutable::now();
        $claimed = $this->newJob(availableAt: $now->subMinutes(50));
        $completed = $this->newJob(availableAt: $now->subMinutes(40));
        $discarded = $this->newJob(availableAt: $now->subMinutes(30));
        $claimable = $this->newJob(availableAt: $now->subMinutes(20));
        $scheduled = $this->newJob(availableAt: $now->addHour());
        $this->queue()->push($claimed, $completed, $discarded, $claimable, $scheduled);

        $batch = $this->claim(limit: 3);
        $this->queue()->settle($batch->token, Settlement::complete($batch->jobs[1]), Settlement::discard($batch->jobs[2], new Reason(['code' => 'poison'])));

        return [
            State::Claimed->value => $claimed->id,
            State::Completed->value => $completed->id,
            State::Discarded->value => $discarded->id,
            State::Claimable->value => $claimable->id,
            State::Scheduled->value => $scheduled->id,
        ];
    }

    /**
     * A null group key becomes `''`.
     *
     * @param list<array{group: ?string, count: int}> $groups
     * @return array<string, int>
     */
    private static function groupMap(array $groups): array
    {
        $map = [];
        foreach ($groups as $group) {
            $map[$group['group'] ?? ''] = $group['count'];
        }
        ksort($map);

        return $map;
    }
}
