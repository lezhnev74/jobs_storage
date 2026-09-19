<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Model;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Lezhnev74\Jobs\Model\DedupKey;
use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Model\JobName;
use Lezhnev74\Jobs\Model\NewJob;
use Lezhnev74\Jobs\Model\PoolName;
use Lezhnev74\Jobs\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NewJob::class)]
final class NewJobTest extends TestCase
{
    public function testDefaultsToEmptyDocumentsAndImmediateAvailability(): void
    {
        $now = Fixtures::at('2026-09-11 13:00:00');
        CarbonImmutable::setTestNow($now);

        try {
            $job = new NewJob(JobId::new(), new JobName('billing.invoice.send'), new PoolName('emails'));
        } finally {
            CarbonImmutable::setTestNow();
        }

        self::assertSame([], $job->payload);
        self::assertTrue($job->availableAt->equalTo($now));
    }

    public function testMakeMintsAnIdAndAcceptsRawNames(): void
    {
        $job = NewJob::make('billing.invoice.send', 'emails.gold', ['customer' => 1]);

        self::assertSame('billing.invoice.send', $job->name->value);
        self::assertSame('emails.gold', $job->pool->value);
        self::assertSame(['customer' => 1], $job->payload);
        self::assertFalse($job->id->equals(NewJob::make('a', 'p')->id));
    }

    public function testCarriesDocumentsAndSchedule(): void
    {
        $at = Fixtures::at('2026-09-11 13:00:00');

        $job = new NewJob(
            JobId::new(),
            new JobName('billing.invoice.send'),
            new PoolName('emails.gold'),
            ['customer' => ['region' => 'eu']],
            $at,
        );

        self::assertSame('eu', $job->payload['customer']['region'] ?? null);
        self::assertSame($at, $job->availableAt);
    }

    public function testDedupKeyDefaultsToTheContentHash(): void
    {
        $pool = new PoolName('emails');
        $name = new JobName('billing.invoice.send');
        $payload = ['customer' => 1];

        $job = new NewJob(JobId::new(), $name, $pool, $payload);

        self::assertTrue($job->dedupKey->equals(DedupKey::ofContent($pool, $name, $payload)));
    }

    public function testTwoJobsOfTheSameContentShareTheDefaultKey(): void
    {
        $first = NewJob::make('billing.invoice.send', 'emails', ['customer' => 1]);
        $second = NewJob::make('billing.invoice.send', 'emails', ['customer' => 1]);

        self::assertFalse($first->id->equals($second->id), 'distinct ids');
        self::assertTrue($first->dedupKey->equals($second->dedupKey));
    }

    public function testDedupKeyIgnoresAvailableAt(): void
    {
        $soon = new NewJob(JobId::new(), new JobName('a'), new PoolName('p'), [], Fixtures::at('2026-09-11 13:00:00'));
        $later = new NewJob(JobId::new(), new JobName('a'), new PoolName('p'), [], Fixtures::at('2027-01-01 00:00:00'));

        self::assertTrue($soon->dedupKey->equals($later->dedupKey));
    }

    public function testDedupWitherReturnsANewInstanceAndLeavesTheOriginalUntouched(): void
    {
        $original = NewJob::make('billing.invoice.send', 'emails', ['customer' => 1]);

        $keyed = $original->dedup('invoice:2026-09:acme');

        self::assertNotSame($original, $keyed);
        self::assertSame('invoice:2026-09:acme', $keyed->dedupKey->value, 'an explicit key is stored verbatim, not re-hashed');
        self::assertTrue($original->dedupKey->equals(DedupKey::ofContent($original->pool, $original->name, $original->payload)));
    }

    public function testDedupPreservesEveryOtherField(): void
    {
        $at = Fixtures::at('2026-09-11 13:00:00');
        $original = new NewJob(JobId::new(), new JobName('a'), new PoolName('p'), ['x' => 1], $at);

        $keyed = $original->dedup('k');

        self::assertTrue($keyed->id->equals($original->id));
        self::assertTrue($keyed->name->equals($original->name));
        self::assertTrue($keyed->pool->equals($original->pool));
        self::assertSame($original->payload, $keyed->payload);
        self::assertTrue($keyed->availableAt->equalTo($at));
    }

    public function testRejectsUnencodablePayload(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NewJob(JobId::new(), new JobName('a'), new PoolName('p'), ['bad' => "\xB1\x31"]);
    }
}
