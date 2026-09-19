<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Driver;

use Lezhnev74\Jobs\Driver\Support\PushReports;
use Lezhnev74\Jobs\Model\JobId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** The split itself is covered by `IdPartitionTest`; this asserts only which side maps to which field. */
#[CoversClass(PushReports::class)]
final class PushReportsTest extends TestCase
{
    public function testStoredIdsAreInsertedAndTheRestAreDeduplicated(): void
    {
        $stored = JobId::new();
        $dropped = JobId::new();

        $report = PushReports::diff([$stored, $dropped], [$stored->value]);

        self::assertSame([$stored], $report->inserted);
        self::assertSame([$dropped], $report->deduplicated);
    }
}
