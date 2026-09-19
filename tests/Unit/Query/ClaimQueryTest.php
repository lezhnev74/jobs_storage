<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Query;

use InvalidArgumentException;
use Lezhnev74\Jobs\Model\PoolName;
use Lezhnev74\Jobs\Query\ClaimQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClaimQuery::class)]
final class ClaimQueryTest extends TestCase
{
    public function testDefaults(): void
    {
        $q = ClaimQuery::pool(new PoolName('emails'));

        self::assertSame('emails', $q->pool->value);
        self::assertNull($q->namePrefix);
        self::assertSame([], $q->payload);
        self::assertSame(ClaimQuery::DEFAULT_LIMIT, $q->limit);
    }

    public function testWithersReturnNewInstancesAndLeaveTheOriginalUntouched(): void
    {
        $base = ClaimQuery::pool(new PoolName('emails'));

        $derived = $base->namePrefix('billing.')
            ->payload('$.customer.region', 'eu')
            ->limit(50);

        self::assertNotSame($base, $derived);
        self::assertNull($base->namePrefix);
        self::assertSame([], $base->payload);
        self::assertSame(ClaimQuery::DEFAULT_LIMIT, $base->limit);

        self::assertSame($base->pool, $derived->pool);
        self::assertSame('billing.', $derived->namePrefix);
        self::assertSame(['$.customer.region' => 'eu'], $derived->payload);
        self::assertSame(50, $derived->limit);
    }

    public function testPayloadPathsAccumulateAndRepeatedPathOverwrites(): void
    {
        $q = ClaimQuery::pool(new PoolName('p'))
            ->payload('$.customer.region', 'eu')
            ->payload('$.tags', ['a', 'b'])
            ->payload('$.customer.region', 'us');

        self::assertSame(['$.customer.region' => 'us', '$.tags' => ['a', 'b']], $q->payload);
    }

    public function testNamePrefixReplacesEarlierPrefix(): void
    {
        $q = ClaimQuery::pool(new PoolName('p'))->namePrefix('billing.')->namePrefix('billing.invoice');

        self::assertSame('billing.invoice', $q->namePrefix);
    }

    public function testLimitBounds(): void
    {
        self::assertSame(1, ClaimQuery::pool(new PoolName('p'))->limit(1)->limit);
        self::assertSame(ClaimQuery::MAX_LIMIT, ClaimQuery::pool(new PoolName('p'))->limit(ClaimQuery::MAX_LIMIT)->limit);
    }

    /** @return iterable<string, array{int}> */
    public static function badLimits(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-5];
        yield 'above max' => [ClaimQuery::MAX_LIMIT + 1];
    }

    #[DataProvider('badLimits')]
    public function testRejectsLimitOutOfBounds(int $limit): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClaimQuery::pool(new PoolName('p'))->limit($limit);
    }

    /** @return iterable<string, array{string}> */
    public static function badPrefixes(): iterable
    {
        yield 'empty' => [''];
        yield 'like wildcard' => ['billing%'];
        yield 'uppercase' => ['Billing.'];
        yield 'whitespace' => ['billing .'];
        yield 'too long' => [str_repeat('a', 129)];
    }

    #[DataProvider('badPrefixes')]
    public function testRejectsIllegalPrefix(string $prefix): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClaimQuery::pool(new PoolName('p'))->namePrefix($prefix);
    }

    /** @return iterable<string, array{string}> */
    public static function badPaths(): iterable
    {
        yield 'root only' => ['$'];
        yield 'no root' => ['customer.region'];
        yield 'array index' => ['$.tags[0]'];
        yield 'trailing dot' => ['$.customer.'];
        yield 'wildcard' => ['$.*'];
    }

    #[DataProvider('badPaths')]
    public function testRejectsIllegalPayloadPath(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClaimQuery::pool(new PoolName('p'))->payload($path, 'x');
    }
}
