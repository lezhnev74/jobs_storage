<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Support\Conformance;

use Carbon\CarbonImmutable;
use Lezhnev74\Jobs\Model\PoolName;
use Lezhnev74\Jobs\Model\Reason;
use Lezhnev74\Jobs\Model\Settlement;
use Lezhnev74\Jobs\Model\Ttl;
use Lezhnev74\Jobs\Model\WorkerId;
use Lezhnev74\Jobs\Query\ClaimQuery;
use Lezhnev74\Jobs\Tests\Support\DriverConformanceTestCase;

/** @phpstan-require-extends DriverConformanceTestCase */
trait ClaimConformance
{
    public function testClaimReturnsOnlyClaimableJobsOfThePoolOldestFirstUpToLimit(): void
    {
        $ids = $this->pushClaimable(3);
        $this->pushClaimable(1, pool: 'other');
        $scheduled = $this->newJob(availableAt: CarbonImmutable::now()->addHour());
        $this->queue()->push($scheduled);

        $first = $this->claim(limit: 2);
        $second = $this->claim(limit: 10);
        $third = $this->claim(limit: 10);

        self::assertSameIds([$ids[0], $ids[1]], $first->jobs, 'oldest available_at first, capped at limit');
        self::assertSameIds([$ids[2]], $second->jobs, 'leased and scheduled jobs are not claimable');
        self::assertTrue($third->isEmpty());
        self::assertCount(0, $third);
    }

    public function testClaimOrdersByAvailableAtNotByCreation(): void
    {
        $now = CarbonImmutable::now();
        $late = $this->newJob(availableAt: $now->subMinutes(10));
        $early = $this->newJob(availableAt: $now->subMinutes(30));
        $middle = $this->newJob(availableAt: $now->subMinutes(20));
        $this->queue()->push($late, $early, $middle);

        self::assertSameIds([$early->id, $middle->id, $late->id], $this->claim(limit: 3)->jobs);
    }

    public function testClaimBatchSizeDefaultsToOne(): void
    {
        $ids = $this->pushClaimable(2);

        $claimed = $this->queue()->claim(ClaimQuery::pool(new PoolName('emails')), new WorkerId('w1'), Ttl::minutes(1));

        self::assertSameIds([$ids[0]], $claimed->jobs);
    }

    public function testClaimNarrowsByNamePrefixAndPayload(): void
    {
        $past = CarbonImmutable::now()->subMinute();
        // Each fixture matches exactly one criterion, so an AND that returns nothing proves both were applied.
        $byName = $this->newJob(name: 'billing.invoice.send', payload: ['customer' => ['region' => 'eu'], 'tier' => 'gold'], availableAt: $past);
        $byRegion = $this->newJob(name: 'other.job', payload: ['customer' => ['region' => 'us'], 'tier' => 'gold'], availableAt: $past);
        $byTier = $this->newJob(name: 'other.job', payload: ['customer' => ['region' => 'eu'], 'tier' => 'silver'], availableAt: $past);
        $this->queue()->push($byName, $byRegion, $byTier);
        $worker = new WorkerId('w1');
        $ttl = Ttl::minutes(5);
        $pool = static fn(): ClaimQuery => ClaimQuery::pool(new PoolName('emails'))->limit(10);

        // The AND-ing claims run first, while every fixture is still unleased, so emptiness cannot come from a lease.
        $noPrefixAndRegion = $this->queue()->claim($pool()->namePrefix('billing.')->payload('$.customer.region', 'us'), $worker, $ttl);
        $noTwoPayloadPaths = $this->queue()->claim($pool()->payload('$.customer.region', 'us')->payload('$.tier', 'silver'), $worker, $ttl);
        $prefixed = $this->queue()->claim($pool()->namePrefix('billing.'), $worker, $ttl);
        $us = $this->queue()->claim($pool()->payload('$.customer.region', 'us'), $worker, $ttl);
        $silver = $this->queue()->claim($pool()->payload('$.tier', 'silver'), $worker, $ttl);

        self::assertSameIds([$byName->id], $prefixed->jobs);
        self::assertSameIds([$byRegion->id], $us->jobs);
        self::assertSameIds([$byTier->id], $silver->jobs);
        self::assertTrue($noPrefixAndRegion->isEmpty(), 'name prefix and payload must both match');
        self::assertTrue($noTwoPayloadPaths->isEmpty(), 'every payload path must match');
    }

    public function testNamePrefixIsLiteralNotAPattern(): void
    {
        $literal = $this->newJob(name: 'a_b.job', availableAt: CarbonImmutable::now()->subMinutes(2));
        $lookalike = $this->newJob(name: 'axb.job', availableAt: CarbonImmutable::now()->subMinutes(3));
        $this->queue()->push($literal, $lookalike);

        $claimed = $this->queue()->claim(ClaimQuery::pool(new PoolName('emails'))->namePrefix('a_b')->limit(10), new WorkerId('w1'), Ttl::minutes(1));

        self::assertSameIds([$literal->id], $claimed->jobs, '`_` in a prefix is a character, not a wildcard');
    }

    public function testPoolVariantsAreDistinctPools(): void
    {
        $gold = $this->newJob(pool: 'emails.gold', availableAt: CarbonImmutable::now()->subMinute());
        $plain = $this->newJob(pool: 'emails', availableAt: CarbonImmutable::now()->subMinute());
        $this->queue()->push($gold, $plain);

        self::assertSameIds([$gold->id], $this->claim(pool: 'emails.gold')->jobs);
        self::assertSameIds([$plain->id], $this->claim(pool: 'emails')->jobs);
    }

    public function testClaimStampsOneLeaseAcrossTheBatch(): void
    {
        $ids = $this->pushClaimable(3);

        $claimed = $this->claim(limit: 3, worker: 'w1');

        self::assertCount(3, $claimed);
        foreach ($claimed->jobs as $job) {
            self::assertNotNull($job->lease);
            self::assertSame('w1', $job->lease->by);
            self::assertTrue($job->lease->till->equalTo($claimed->leasedUntil), 'one now() + ttl for the whole batch');
            self::assertTrue($job->lease->till->greaterThan($job->availableAt));
            self::assertSame(1, $job->retry->attempts, 'attempts increments at claim');
            self::assertNull($job->telemetry->lastClaimedBy, 'the claimer is on the lease while it is held');
            self::assertTrue($job->updatedAt->greaterThan($job->createdAt));
        }
        self::assertSame('w1', $claimed->token->worker->value);
        self::assertTrue($this->queue()->heartbeat($claimed->token, Ttl::minutes(1), ...$ids)->isClean(), 'one token covers the batch');
    }

    public function testEveryClaimMintsAFreshToken(): void
    {
        $this->pushClaimable(2);

        $first = $this->claim(limit: 1, worker: 'w1');
        $second = $this->claim(limit: 1, worker: 'w1');

        self::assertFalse($first->token->equals($second->token), 'one token per claim event, even for the same worker');
        self::assertFalse($this->claim(limit: 1)->token->equals($first->token), 'an empty claim still mints one');
    }

    public function testClaimedJobsAreNotReclaimableUntilTheLeaseExpires(): void
    {
        [$id] = $this->pushClaimable(1);
        $first = $this->claim(worker: 'w1', ttl: $this->minTtl());

        self::assertTrue($this->claim(worker: 'w2')->isEmpty(), 'a live lease is not claimable');
        $this->waitOutLease();
        $second = $this->claim(worker: 'w2');

        self::assertSameIds([$id], $second->jobs, 'an expired lease is claimable again');
        $job = $second->jobs[0];
        self::assertSame(2, $job->retry->attempts);
        self::assertNotNull($job->lease);
        self::assertSame('w2', $job->lease->by);
        self::assertNotNull($job->telemetry->abandonment, 'token still attached at re-claim = previous worker died silently');
        self::assertSame(1, $job->telemetry->abandonment->count);
        self::assertSame('w1', $job->telemetry->abandonment->by);
        self::assertTrue($job->telemetry->abandonment->at->equalTo($first->leasedUntil), 'abandoned at the expired lease\'s till');
    }

    public function testAbandonmentAccumulatesAndRemembersTheLatestDeserter(): void
    {
        [$id] = $this->pushClaimable(1);
        $this->claim(worker: 'w1', ttl: $this->minTtl());
        $this->waitOutLease();
        $second = $this->claim(worker: 'w2', ttl: $this->minTtl());
        $this->waitOutLease();

        $third = $this->claim(worker: 'w3');

        self::assertSameIds([$id], $third->jobs);
        $abandonment = $third->jobs[0]->telemetry->abandonment;
        self::assertNotNull($abandonment);
        self::assertSame(2, $abandonment->count);
        self::assertSame('w2', $abandonment->by);
        self::assertTrue($abandonment->at->equalTo($second->leasedUntil));
        self::assertSame(3, $third->jobs[0]->retry->attempts);
    }

    public function testReleasedJobIsReclaimableAtOnceWithoutAbandonment(): void
    {
        [$id] = $this->pushClaimable(1);
        $first = $this->claim(worker: 'w1');

        self::assertTrue($this->queue()->settle($first->token, Settlement::release($first->jobs[0]))->isClean());
        $second = $this->claim(worker: 'w2');

        self::assertSameIds([$id], $second->jobs);
        self::assertSame(2, $second->jobs[0]->retry->attempts);
        self::assertNull($second->jobs[0]->telemetry->abandonment, 'a clean release never counts');
    }

    public function testSettledThenReclaimedJobCarriesNoAbandonment(): void
    {
        [$id] = $this->pushClaimable(1);
        $first = $this->claim(worker: 'w1', ttl: $this->minTtl());
        $this->queue()->settle($first->token, Settlement::reschedule($first->jobs[0], new Reason(['code' => 'again']), CarbonImmutable::now()->subMinute()));
        $this->waitOutLease();

        $second = $this->claim(worker: 'w2');

        self::assertSameIds([$id], $second->jobs);
        self::assertNull($second->jobs[0]->telemetry->abandonment, 'the settlement cleared the token before the expiry');
    }
}
