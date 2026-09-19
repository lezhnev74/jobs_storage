<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Support\Conformance;

use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Model\Ttl;
use Lezhnev74\Jobs\Tests\Support\DriverConformanceTestCase;

/** @phpstan-require-extends DriverConformanceTestCase */
trait HeartbeatConformance
{
    public function testHeartbeatExtendsTheLease(): void
    {
        [$id] = $this->pushClaimable(1);
        $claimed = $this->claim(worker: 'w1', ttl: $this->minTtl());

        $report = $this->queue()->heartbeat($claimed->token, Ttl::minutes(5), $id);
        $this->waitOutLease();

        self::assertSame([$id->value], [$report->acked[0]->value]);
        self::assertTrue($report->isClean());
        $lease = $this->getJob($id)->lease;
        self::assertNotNull($lease);
        self::assertTrue($lease->till->greaterThan($claimed->leasedUntil));
        self::assertTrue($this->claim(worker: 'w2')->isEmpty(), 'the extended lease still holds');
    }

    public function testHeartbeatWithAForeignTokenOrUnknownIdIsStaleButNotCounted(): void
    {
        [$id] = $this->pushClaimable(1);
        $claimed = $this->claim(worker: 'w1');
        $foreign = $this->claim(worker: 'w2', pool: 'other')->token;
        $unknown = JobId::new();

        $report = $this->queue()->heartbeat($foreign, Ttl::minutes(1), $id, $unknown);

        self::assertSame([], $report->acked);
        self::assertTrue($report->isStale($id));
        self::assertTrue($report->isStale($unknown));
        self::assertNull($this->getJob($id)->telemetry->staleAck, 'stale heartbeats are reported, not counted');
        self::assertTrue($this->queue()->heartbeat($claimed->token, Ttl::minutes(1))->isClean(), 'no ids, nothing to report');
    }

    public function testHeartbeatAfterExpiryStillLandsUntilSomeoneReclaims(): void
    {
        [$id] = $this->pushClaimable(1);
        $claimed = $this->claim(worker: 'w1', ttl: $this->minTtl());
        $this->waitOutLease();

        self::assertTrue($this->queue()->heartbeat($claimed->token, Ttl::minutes(5), $id)->isAcked($id), 'expiry alone invalidates nothing');
        self::assertTrue($this->claim(worker: 'w2')->isEmpty(), 'the revived lease holds again');
    }

    public function testHeartbeatOfAMixedBatchReportsOnlyTheReclaimedJobsStale(): void
    {
        [$a, $b] = $this->pushClaimable(2);
        $claimed = $this->claim(limit: 2, worker: 'w1', ttl: $this->minTtl());
        $this->waitOutLease();
        $this->claim(limit: 1, worker: 'w2');

        $report = $this->queue()->heartbeat($claimed->token, Ttl::minutes(5), $a, $b);

        self::assertTrue($report->isStale($a), 'the oldest job went to w2');
        self::assertTrue($report->isAcked($b));
        self::assertNull($this->getJob($a)->telemetry->staleAck);
        self::assertSame('w2', $this->getJob($a)->lease?->by);
        self::assertSame('w1', $this->getJob($b)->lease?->by);
    }
}
