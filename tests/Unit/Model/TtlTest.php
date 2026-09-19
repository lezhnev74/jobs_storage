<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Model;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use InvalidArgumentException;
use Lezhnev74\Jobs\Model\Ttl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Ttl::class)]
final class TtlTest extends TestCase
{
    public function testNamedConstructors(): void
    {
        self::assertSame(30, Ttl::seconds(30)->seconds);
        self::assertSame(120, Ttl::minutes(2)->seconds);
        self::assertSame(7200, Ttl::hours(2)->seconds);
        self::assertSame(90, Ttl::fromInterval(CarbonInterval::minutes(1)->addSeconds(30))->seconds);
    }

    public function testFromIntervalTruncatesSubSeconds(): void
    {
        self::assertSame(1, Ttl::fromInterval(CarbonInterval::milliseconds(1500))->seconds);
    }

    /** @return iterable<string, array{callable(): Ttl}> */
    public static function nonPositive(): iterable
    {
        yield 'zero seconds' => [static fn(): Ttl => Ttl::seconds(0)];
        yield 'negative minutes' => [static fn(): Ttl => Ttl::minutes(-1)];
        yield 'negative hours' => [static fn(): Ttl => Ttl::hours(-1)];
        yield 'sub-second interval' => [static fn(): Ttl => Ttl::fromInterval(CarbonInterval::milliseconds(999))];
    }

    /** @param callable(): Ttl $build */
    #[DataProvider('nonPositive')]
    public function testRejectsNonPositive(callable $build): void
    {
        $this->expectException(InvalidArgumentException::class);

        $build();
    }

    public function testExpiresAfterAndToInterval(): void
    {
        $from = CarbonImmutable::parse('2026-09-11 12:00:00', 'UTC');

        $till = Ttl::seconds(90)->expiresAfter($from);

        self::assertSame('2026-09-11 12:01:30', $till->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-11 12:00:00', $from->format('Y-m-d H:i:s'));
        self::assertSame(90.0, Ttl::seconds(90)->toInterval()->totalSeconds);
    }
}
