<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Query;

use Lezhnev74\Jobs\Query\GroupBy;
use Lezhnev74\Jobs\Query\Order;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GroupBy::class)]
#[CoversClass(Order::class)]
final class EnumsTest extends TestCase
{
    public function testOrderCoversIdAndAvailableAtInBothDirections(): void
    {
        self::assertSame(
            ['IdAsc', 'IdDesc', 'AvailableAtAsc', 'AvailableAtDesc'],
            array_map(static fn(Order $o): string => $o->name, Order::cases()),
        );
    }

    public function testGroupByCoversTheFiveAggregationAxes(): void
    {
        self::assertSame(
            ['State', 'Pool', 'NamePrefix', 'ReasonCode', 'Worker'],
            array_map(static fn(GroupBy $g): string => $g->name, GroupBy::cases()),
        );
    }
}
