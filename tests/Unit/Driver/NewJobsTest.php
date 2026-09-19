<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Driver;

use Lezhnev74\Jobs\Driver\Support\NewJobs;
use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Model\JobName;
use Lezhnev74\Jobs\Model\NewJob;
use Lezhnev74\Jobs\Model\PoolName;
use Lezhnev74\Jobs\Tests\Support\DescribedJob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NewJobs::class)]
#[CoversClass(DescribedJob::class)]
final class NewJobsTest extends TestCase
{
    public function testEmptyVariadicYieldsAnEmptyList(): void
    {
        self::assertSame([], NewJobs::of());
    }

    public function testNewJobsPassThroughUntouched(): void
    {
        $job = self::newJob();

        self::assertSame([$job], NewJobs::of($job));
    }

    public function testDescribedJobIsConvertedOnceAndNotCopied(): void
    {
        $job = self::newJob();
        $described = new DescribedJob($job);

        self::assertSame([$job], NewJobs::of($described), 'the returned NewJob is passed through by identity');
        self::assertSame(1, $described->conversions, 'toJob() is called exactly once per normalization');
    }

    public function testMixedVariadicNormalizesInArgumentOrder(): void
    {
        $first = self::newJob('a');
        $second = self::newJob('b');
        $third = self::newJob('c');

        $normalized = NewJobs::of($first, new DescribedJob($second), $third);

        self::assertSame([$first, $second, $third], $normalized);
    }

    private static function newJob(string $name = 'billing.invoice.send'): NewJob
    {
        return new NewJob(JobId::new(), new JobName($name), new PoolName('emails'));
    }
}
