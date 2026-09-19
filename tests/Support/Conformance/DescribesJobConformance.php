<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Support\Conformance;

use Carbon\CarbonImmutable;
use Lezhnev74\Jobs\Tests\Support\DescribedJob;
use Lezhnev74\Jobs\Tests\Support\DriverConformanceTestCase;

/**
 * A `DescribesJob` must be indistinguishable from pushing its `toJob()` result directly: the conversion is a
 * boundary concern the driver resolves before any SQL.
 *
 * @phpstan-require-extends DriverConformanceTestCase
 */
trait DescribesJobConformance
{
    public function testPushOfADescribedJobStoresTheSameRow(): void
    {
        $availableAt = CarbonImmutable::parse('2030-01-02 03:04:05', 'UTC');
        $new = $this->newJob(availableAt: $availableAt);
        $described = new DescribedJob($new);

        $this->queue()->push($described);
        $job = $this->getJob($new->id);

        self::assertSame(1, $described->conversions, 'toJob() is called exactly once per push');
        self::assertTrue($job->name->equals($new->name));
        self::assertSame($new->pool->value, $job->pool->value);
        self::assertSameDocument($new->payload, $job->payload);
        self::assertTrue($job->availableAt->equalTo($availableAt));
    }

    public function testPushStoresEveryJobOfAMixedBatch(): void
    {
        $plain = $this->newJob();
        $described = $this->newJob(pool: 'other');

        $this->queue()->push($plain, new DescribedJob($described));

        $this->getJob($plain->id);
        $this->getJob($described->id);
        self::assertSame(1, $this->monitor()->count($this->poolQuery()));
        self::assertSame(1, $this->monitor()->count($this->poolQuery('other')));
    }

    public function testPushOfADescribedJobIsIdempotentOnTheCarriedId(): void
    {
        $new = $this->newJob(name: 'first');

        $this->queue()->push(new DescribedJob($new));
        $this->queue()->push(new DescribedJob($this->newJob($new->id, name: 'second')));

        self::assertSame('first', $this->getJob($new->id)->name->value, 'the id the NewJob carries governs idempotency');
        self::assertSame(1, $this->monitor()->count($this->poolQuery()));
    }
}
