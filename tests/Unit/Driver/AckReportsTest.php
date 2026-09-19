<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Driver;

use Lezhnev74\Jobs\Driver\Support\AckReports;
use Lezhnev74\Jobs\Model\JobId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** The split itself is covered by `IdPartitionTest`; this asserts only which side maps to which field. */
#[CoversClass(AckReports::class)]
final class AckReportsTest extends TestCase
{
    public function testReturnedIdsAreAckedAndTheRestAreStale(): void
    {
        $acked = JobId::new();
        $stale = JobId::new();

        $report = AckReports::diff([$acked, $stale], [$acked->value]);

        self::assertSame([$acked], $report->acked);
        self::assertSame([$stale], $report->stale);
    }
}
