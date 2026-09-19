<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Support\Conformance;

use Carbon\CarbonImmutable;
use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Model\NewJob;
use Lezhnev74\Jobs\Model\Reason;
use Lezhnev74\Jobs\Model\Settlement;
use Lezhnev74\Jobs\Query\State;
use Lezhnev74\Jobs\Tests\Support\DriverConformanceTestCase;

/**
 * The second identity a job carries: `JobId` answers "did my push land?", the dedup key answers "is this work
 * already queued?". Uniqueness holds per pool over non-terminal rows only, and a collision drops the new job rather
 * than mutating the stored one.
 *
 * These tests pass the key explicitly (or build two jobs of identical content on purpose); the shared `newJob()`
 * fixture otherwise nonces the key so unrelated tests are never deduplicated.
 *
 * @phpstan-require-extends DriverConformanceTestCase
 */
trait DedupConformance
{
    public function testTheContentKeyIsStoredAndReadableBack(): void
    {
        $new = NewJob::make('billing.invoice.send', 'emails', ['customer' => 7]);

        $this->queue()->push($new);

        self::assertTrue($this->getJob($new->id)->dedupKey->equals($new->dedupKey));
    }

    public function testAnExplicitKeyIsStoredVerbatim(): void
    {
        $new = $this->newJob(dedupKey: 'invoice:2026-09:acme');

        $this->queue()->push($new);

        self::assertSame('invoice:2026-09:acme', $this->getJob($new->id)->dedupKey->value);
    }

    public function testANonAsciiExplicitKeyRoundTripsAndCollides(): void
    {
        $first = $this->newJob(dedupKey: 'invoice:Müller:😀');
        $second = $this->newJob(dedupKey: 'invoice:Müller:😀');

        $this->queue()->push($first);

        self::assertSame('invoice:Müller:😀', $this->getJob($first->id)->dedupKey->value);
        self::assertTrue($this->queue()->push($second)->wasDeduplicated($second->id));
    }

    public function testASecondPushOfIdenticalContentIsDroppedWhilePending(): void
    {
        $first = NewJob::make('billing.invoice.send', 'emails', ['customer' => 7]);
        $second = NewJob::make('billing.invoice.send', 'emails', ['customer' => 7]);
        self::assertTrue($first->dedupKey->equals($second->dedupKey), 'identical content, identical default key');

        $firstReport = $this->queue()->push($first);
        $secondReport = $this->queue()->push($second);

        self::assertTrue($firstReport->wasInserted($first->id));
        self::assertTrue($secondReport->wasDeduplicated($second->id));
        self::assertFalse($secondReport->isClean());
        self::assertNull($this->monitor()->get($second->id), 'the dropped job leaves no row');
        self::assertSame(1, $this->monitor()->count($this->poolQuery()));
    }

    public function testTheExistingRowIsNeverMutatedByACollidingPush(): void
    {
        $stored = $this->newJob(dedupKey: 'k', payload: ['v' => 'first'], availableAt: CarbonImmutable::now()->subHour());
        $this->queue()->push($stored);
        $before = $this->getJob($stored->id);

        $this->queue()->push($this->newJob(dedupKey: 'k', payload: ['v' => 'second'], availableAt: CarbonImmutable::now()));

        $after = $this->getJob($stored->id);
        self::assertSameDocument(['v' => 'first'], $after->payload);
        self::assertTrue($after->availableAt->equalTo($before->availableAt));
        self::assertTrue($after->updatedAt->equalTo($before->updatedAt), 'push never writes to the colliding row');
    }

    public function testTheKeyIsFreeAgainOnceTheFirstJobCompleted(): void
    {
        $first = $this->newJob(dedupKey: 'k', availableAt: CarbonImmutable::now()->subHour());
        $this->queue()->push($first);
        $claimed = $this->claim();
        $this->queue()->settle($claimed->token, Settlement::complete($claimed->jobs[0]));

        $second = $this->newJob(dedupKey: 'k', availableAt: CarbonImmutable::now()->subHour());
        $report = $this->queue()->push($second);

        self::assertTrue($report->isClean());
        self::assertNotNull($this->getJob($first->id)->termination, 'the finished run is preserved untouched');
        self::assertSame(State::Claimable, $this->getJob($second->id)->state());
        self::assertSame(2, $this->monitor()->count($this->poolQuery()));
        self::assertSameIdSet([$second->id], $this->claim(worker: 'w2'), 'the fresh row is claimable');
    }

    public function testTheKeyIsFreeAgainOnceTheFirstJobWasDiscarded(): void
    {
        $first = $this->newJob(dedupKey: 'k', availableAt: CarbonImmutable::now()->subHour());
        $this->queue()->push($first);
        $claimed = $this->claim();
        $this->queue()->settle($claimed->token, Settlement::discard($claimed->jobs[0], new Reason(['code' => 'done'])));

        $second = $this->newJob(dedupKey: 'k', availableAt: CarbonImmutable::now()->subHour());

        self::assertTrue($this->queue()->push($second)->isClean());
        self::assertSame(2, $this->monitor()->count($this->poolQuery()));
    }

    /** The key is the authority, not the payload: that a wrong key drops unrelated work is a caller bug, surfaced. */
    public function testAnExplicitKeyCollidesEvenWhenThePayloadsDiffer(): void
    {
        $this->queue()->push($this->newJob(dedupKey: 'k', payload: ['a' => 1]));

        $second = $this->newJob(dedupKey: 'k', payload: ['b' => 2]);

        self::assertTrue($this->queue()->push($second)->wasDeduplicated($second->id));
        self::assertSame(1, $this->monitor()->count($this->poolQuery()));
    }

    public function testDifferentExplicitKeysWithIdenticalPayloadsBothInsert(): void
    {
        $a = $this->newJob(dedupKey: 'k1');
        $b = $this->newJob(dedupKey: 'k2');

        self::assertTrue($this->queue()->push($a, $b)->isClean());
        self::assertSame(2, $this->monitor()->count($this->poolQuery()));
    }

    public function testTheSameContentInAnotherPoolIsIndependent(): void
    {
        $here = NewJob::make('billing.invoice.send', 'emails', ['customer' => 7]);
        $there = NewJob::make('billing.invoice.send', 'other', ['customer' => 7]);

        self::assertTrue($this->queue()->push($here, $there)->isClean());
        self::assertSame(1, $this->monitor()->count($this->poolQuery()));
        self::assertSame(1, $this->monitor()->count($this->poolQuery('other')));
    }

    /** Uniqueness is `(pool, dedup_key)`; an explicit key need not encode the pool, so the column must scope it. */
    public function testTheSameExplicitKeyInAnotherPoolIsIndependent(): void
    {
        $here = $this->newJob(pool: 'emails', dedupKey: 'k');
        $there = $this->newJob(pool: 'other', dedupKey: 'k');

        self::assertTrue($this->queue()->push($here, $there)->isClean());
        self::assertSame(1, $this->monitor()->count($this->poolQuery()));
        self::assertSame(1, $this->monitor()->count($this->poolQuery('other')));
    }

    public function testADifferentNameWithTheIdenticalPayloadIsDifferentWork(): void
    {
        $send = NewJob::make('billing.invoice.send', 'emails', ['customer' => 7]);
        $resend = NewJob::make('billing.invoice.resend', 'emails', ['customer' => 7]);

        self::assertTrue($this->queue()->push($send, $resend)->isClean());
        self::assertSame(2, $this->monitor()->count($this->poolQuery()));
    }

    /** Scheduling time is not identity: "the same work, maybe sooner" must still deduplicate. */
    public function testAvailableAtIsNotPartOfTheIdentity(): void
    {
        $later = NewJob::make('billing.invoice.send', 'emails', ['customer' => 7]);
        $sooner = new NewJob(JobId::new(), $later->name, $later->pool, $later->payload, CarbonImmutable::now()->subHour());

        $this->queue()->push($later);

        self::assertTrue($this->queue()->push($sooner)->wasDeduplicated($sooner->id));
        self::assertSame(1, $this->monitor()->count($this->poolQuery()));
    }

    public function testACollidingBatchStillInsertsItsNewJobs(): void
    {
        $this->queue()->push($this->newJob(dedupKey: 'taken'));
        $colliding = $this->newJob(dedupKey: 'taken');
        $fresh = $this->newJob(dedupKey: 'free');

        $report = $this->queue()->push($colliding, $fresh);

        self::assertTrue($report->wasDeduplicated($colliding->id));
        self::assertTrue($report->wasInserted($fresh->id), 'the batch is not aborted by one collision');
        self::assertSame(2, $this->monitor()->count($this->poolQuery()));
    }

    public function testTwoCopiesOfOneKeyInASingleBatchInsertExactlyOne(): void
    {
        $a = $this->newJob(dedupKey: 'k');
        $b = $this->newJob(dedupKey: 'k');

        $report = $this->queue()->push($a, $b);

        self::assertSame(1, $this->monitor()->count($this->poolQuery()), 'self-collision inside one statement');
        self::assertCount(1, $report->inserted);
        self::assertCount(1, $report->deduplicated);
        self::assertTrue($report->wasInserted($a->id), 'the earlier job of the batch wins');
        self::assertTrue($report->wasDeduplicated($b->id));
    }

    public function testDedupOfAClaimedJobLeavesTheLiveLeaseUntouched(): void
    {
        $this->queue()->push($this->newJob(dedupKey: 'k', availableAt: CarbonImmutable::now()->subHour()));
        $claimed = $this->claim();
        $leased = $this->getJob($claimed->jobs[0]->id);

        $colliding = $this->newJob(dedupKey: 'k');

        self::assertTrue($this->queue()->push($colliding)->wasDeduplicated($colliding->id));
        $after = $this->getJob($leased->id);
        self::assertSame($leased->lease?->by, $after->lease?->by);
        self::assertTrue($after->lease?->till->equalTo($leased->lease->till) ?? false, 'the lease is not extended or shortened');
        self::assertSame(1, $after->retry->attempts, 'no counter moves');
        self::assertNull($after->telemetry->abandonment);
        self::assertTrue($this->queue()->heartbeat($claimed->token, $this->minTtl(), $leased->id)->isClean(), 'the token still owns the job');
    }

    public function testRePushOfTheSameIdIsStillANoOpRegardlessOfTheKey(): void
    {
        $id = JobId::new();
        $first = $this->newJob($id, name: 'first', dedupKey: 'k1');
        $second = $this->newJob($id, name: 'second', dedupKey: 'k2');

        self::assertTrue($this->queue()->push($first)->wasInserted($id));
        self::assertTrue($this->queue()->push($second)->wasDeduplicated($id), 'a duplicate id reports as deduplicated too');
        self::assertSame('first', $this->getJob($id)->name->value);
        self::assertSame(1, $this->monitor()->count($this->poolQuery()));
    }

    public function testAnEmptyPushReportsNothing(): void
    {
        $report = $this->queue()->push();

        self::assertTrue($report->isClean());
        self::assertSame([], $report->inserted);
    }
}
