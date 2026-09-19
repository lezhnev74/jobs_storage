<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Model;

use Lezhnev74\Jobs\Model\AckReport;
use Lezhnev74\Jobs\Model\JobId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AckReport::class)]
final class AckReportTest extends TestCase
{
    public function testCleanReport(): void
    {
        $a = JobId::new();

        $report = new AckReport([$a], []);

        self::assertTrue($report->isClean());
        self::assertTrue($report->isAcked($a));
        self::assertFalse($report->isStale($a));
    }

    public function testPartialStalenessIsReportedPerId(): void
    {
        $acked = JobId::new();
        $stale = JobId::new();
        $unknown = JobId::new();

        $report = new AckReport([$acked], [$stale]);

        self::assertFalse($report->isClean());
        self::assertTrue($report->isAcked(JobId::fromString($acked->value)));
        self::assertTrue($report->isStale($stale));
        self::assertFalse($report->isAcked($stale));
        self::assertFalse($report->isStale($unknown));
        self::assertSame([$acked], $report->acked);
        self::assertSame([$stale], $report->stale);
    }

    public function testMergingNothingYieldsACleanEmptyReport(): void
    {
        $merged = AckReport::merge();

        self::assertTrue($merged->isClean());
        self::assertSame([], $merged->acked);
        self::assertSame([], $merged->stale);
    }

    public function testMergingOneReportIsIdentity(): void
    {
        $acked = JobId::new();
        $stale = JobId::new();

        $merged = AckReport::merge(new AckReport([$acked], [$stale]));

        self::assertSame([$acked], $merged->acked);
        self::assertSame([$stale], $merged->stale);
    }

    public function testMergeCollapsesDuplicatesKeepingFirstAppearanceOrder(): void
    {
        $a = JobId::new();
        $b = JobId::new();
        $c = JobId::new();

        $merged = AckReport::merge(
            new AckReport([$a, $b], [$c]),
            new AckReport([JobId::fromString($b->value), $a], [JobId::fromString($c->value)]),
        );

        self::assertSame([$a, $b], $merged->acked);
        self::assertSame([$c], $merged->stale);
    }

    public function testAnIdAckedInOneRoundAndStaleInAnotherIsStaleOnly(): void
    {
        $id = JobId::new();

        $merged = AckReport::merge(new AckReport([$id], []), new AckReport([], [$id]));

        self::assertFalse($merged->isClean());
        self::assertSame([], $merged->acked);
        self::assertTrue($merged->isStale($id));
        self::assertFalse($merged->isAcked($id));
    }
}
