<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Driver;

use Lezhnev74\Jobs\Driver\Support\IdPartition;
use Lezhnev74\Jobs\Model\JobId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdPartition::class)]
final class IdPartitionTest extends TestCase
{
    public function testEveryRequestedIdLandsInExactlyOneListInRequestOrder(): void
    {
        $a = JobId::new();
        $b = JobId::new();
        $c = JobId::new();

        [$matched, $missing] = IdPartition::split([$a, $b, $c], [$c->value, $a->value]);

        self::assertSame([$a, $c], $matched);
        self::assertSame([$b], $missing);
    }

    public function testReturnedIdsAreComparedCaseInsensitively(): void
    {
        $a = JobId::new();

        [$matched, $missing] = IdPartition::split([$a], [strtoupper($a->value)]);

        self::assertSame([$a], $matched);
        self::assertSame([], $missing);
    }

    /** A batch carrying the same id twice touches one row, so the split must mention that id exactly once. */
    public function testDuplicateRequestsCollapseAndUnknownReturnsAreIgnored(): void
    {
        $a = JobId::new();
        $twin = JobId::fromString($a->value);

        [$matched, $missing] = IdPartition::split([$a, $twin], [$a->value, JobId::new()->value]);

        self::assertSame([$a], $matched);
        self::assertSame([], $missing);
    }

    public function testEmptyRequestYieldsEmptyLists(): void
    {
        self::assertSame([[], []], IdPartition::split([], []));
    }
}
