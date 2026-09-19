<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Support\Conformance;

use Carbon\CarbonImmutable;
use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Model\NewJob;
use Lezhnev74\Jobs\Model\Settlement;
use Lezhnev74\Jobs\Query\State;
use Lezhnev74\Jobs\Tests\Support\DriverConformanceTestCase;

/** @phpstan-require-extends DriverConformanceTestCase */
trait PushConformance
{
    public function testGetUnknownIdIsNull(): void
    {
        self::assertNull($this->monitor()->get(JobId::new()));
    }

    public function testPushThenGetReturnsTheSameJob(): void
    {
        $availableAt = CarbonImmutable::parse('2030-01-02 03:04:05', 'UTC');
        $new = $this->newJob(availableAt: $availableAt);

        $this->queue()->push($new);
        $job = $this->getJob($new->id);

        self::assertTrue($job->id->equals($new->id));
        self::assertTrue($job->name->equals($new->name));
        self::assertSame($new->pool->value, $job->pool->value);
        self::assertSameDocument($new->payload, $job->payload);
        self::assertTrue($job->availableAt->equalTo($availableAt));
        self::assertNull($job->termination);
        self::assertNull($job->lease);
        self::assertFreshRetry($job);
        self::assertNoTelemetry($job);
        self::assertTrue($job->updatedAt->equalTo($job->createdAt));
    }

    public function testPushWithoutAvailableAtIsImmediatelyClaimable(): void
    {
        $new = $this->newJob();

        $this->queue()->push($new);
        $job = $this->getJob($new->id);

        self::assertTrue($job->availableAt->equalTo($new->availableAt), 'the construction-time stamp is stored as-is');
        self::assertSame(State::Claimable, $job->state());
    }

    public function testPushReportsEveryStoredJob(): void
    {
        $batch = [$this->newJob(), $this->newJob(pool: 'other')];

        $report = $this->queue()->push(...$batch);

        self::assertTrue($report->isClean());
        self::assertSame(array_map(static fn(NewJob $new): JobId => $new->id, $batch), $report->inserted, 'push order is preserved');
    }

    public function testPushIsIdempotentOnDuplicateId(): void
    {
        $id = JobId::new();
        $first = $this->newJob($id, name: 'first');
        $second = $this->newJob($id, name: 'second');

        $this->queue()->push($first);
        $this->queue()->push($second);
        $this->queue()->push($first, $second);

        self::assertSame('first', $this->getJob($id)->name->value, 'the first push wins, re-pushes are no-ops');
        self::assertSame(1, $this->monitor()->count($this->poolQuery()));
    }

    public function testRePushOfASettledJobChangesNothing(): void
    {
        [$id] = $this->pushClaimable(1);
        $claimed = $this->claim();
        $this->queue()->settle($claimed->token, Settlement::complete($claimed->jobs[0]));

        $this->queue()->push($this->newJob($id, availableAt: CarbonImmutable::now()->subHour()));

        self::assertNotNull($this->getJob($id)->termination, 'a retry of a lost push never revives a finished job');
        self::assertTrue($this->claim(worker: 'w2')->isEmpty());
    }

    public function testPushStoresEveryJobOfABatch(): void
    {
        $batch = [$this->newJob(), $this->newJob(), $this->newJob(pool: 'other')];

        $this->queue()->push(...$batch);

        foreach ($batch as $new) {
            $this->getJob($new->id);
        }
        self::assertSame(2, $this->monitor()->count($this->poolQuery()));
        self::assertSame(1, $this->monitor()->count($this->poolQuery('other')));
    }

    public function testPushOfAnEmptyBatchIsANoOp(): void
    {
        $this->queue()->push();

        self::assertSame(0, $this->monitor()->count($this->poolQuery()));
    }
}
