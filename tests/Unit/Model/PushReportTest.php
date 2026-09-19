<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Model;

use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Model\PushReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PushReport::class)]
final class PushReportTest extends TestCase
{
    public function testCleanReport(): void
    {
        $a = JobId::new();

        $report = new PushReport([$a], []);

        self::assertTrue($report->isClean());
        self::assertTrue($report->wasInserted($a));
        self::assertFalse($report->wasDeduplicated($a));
    }

    public function testDeduplicationIsReportedPerId(): void
    {
        $inserted = JobId::new();
        $dropped = JobId::new();
        $unknown = JobId::new();

        $report = new PushReport([$inserted], [$dropped]);

        self::assertFalse($report->isClean());
        self::assertTrue($report->wasInserted(JobId::fromString($inserted->value)));
        self::assertTrue($report->wasDeduplicated($dropped));
        self::assertFalse($report->wasInserted($dropped));
        self::assertFalse($report->wasDeduplicated($unknown));
        self::assertSame([$inserted], $report->inserted);
        self::assertSame([$dropped], $report->deduplicated);
    }

    public function testEmptyReportIsClean(): void
    {
        $report = new PushReport([], []);

        self::assertTrue($report->isClean());
        self::assertFalse($report->wasInserted(JobId::new()));
    }
}
