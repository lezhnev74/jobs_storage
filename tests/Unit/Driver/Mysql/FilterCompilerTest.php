<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Driver\Mysql;

use Lezhnev74\Jobs\Driver\Mysql\FilterCompiler;
use Lezhnev74\Jobs\Model\DedupKey;
use Lezhnev74\Jobs\Model\PoolName;
use Lezhnev74\Jobs\Query\ClaimQuery;
use Lezhnev74\Jobs\Query\JobQuery;
use Lezhnev74\Jobs\Query\State;
use Lezhnev74\Jobs\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FilterCompiler::class)]
final class FilterCompilerTest extends TestCase
{
    private const NOT_TERMINAL = 'completed_at IS NULL AND discarded_at IS NULL';
    private const NOT_LEASED = '(consumed_till IS NULL OR consumed_till <= NOW(6))';

    public function testPoolOnly(): void
    {
        $sql = FilterCompiler::claim(ClaimQuery::pool(new PoolName('emails.gold')));

        self::assertSame('pool = ?', $sql->text);
        self::assertSame(['emails.gold'], $sql->params);
    }

    public function testAllCriteriaInOrderWithEscapedPrefixAndContainmentTests(): void
    {
        $q = ClaimQuery::pool(new PoolName('emails'))
            ->namePrefix('billing_v2.')
            ->payload('$.customer.region', 'eu')
            ->payload('$.amount', 12.5);

        $sql = FilterCompiler::claim($q);

        self::assertSame(
            'pool = ? AND name LIKE ? AND JSON_CONTAINS(payload, CAST(? AS JSON), ?)'
            . ' AND JSON_CONTAINS(payload, CAST(? AS JSON), ?)',
            $sql->text,
        );
        self::assertSame(
            ['emails', 'billing\_v2.%', '"eu"', '$.customer.region', '12.5', '$.amount'],
            $sql->params,
        );
    }

    public function testPayloadOnlyFilterCompilesToOneContainmentTest(): void
    {
        $q = ClaimQuery::pool(new PoolName('emails'))->payload('$.customer.region', 'eu');

        $sql = FilterCompiler::claim($q);

        self::assertSame('pool = ? AND JSON_CONTAINS(payload, CAST(? AS JSON), ?)', $sql->text);
        self::assertSame(['emails', '"eu"', '$.customer.region'], $sql->params);
    }

    public function testEachPayloadPathIsItsOwnTestSoTheyAccumulate(): void
    {
        $q = ClaimQuery::pool(new PoolName('p'))->payload('$.a', 'x')->payload('$.a.b', 'y');

        self::assertSame(
            'pool = ? AND JSON_CONTAINS(payload, CAST(? AS JSON), ?) AND JSON_CONTAINS(payload, CAST(? AS JSON), ?)',
            FilterCompiler::claim($q)->text,
        );
        self::assertSame(['p', '"x"', '$.a', '"y"', '$.a.b'], FilterCompiler::claim($q)->params);
    }

    public function testJobQueryWithOnlyClaimCriteriaCompilesLikeTheClaim(): void
    {
        $claim = ClaimQuery::pool(new PoolName('p'))->namePrefix('a.');

        $sql = FilterCompiler::job(JobQuery::from($claim));

        self::assertSame(FilterCompiler::claim($claim)->text, $sql->text);
        self::assertSame(FilterCompiler::claim($claim)->params, $sql->params);
    }

    /** @return iterable<string, array{State, string}> */
    public static function statePredicates(): iterable
    {
        yield 'discarded' => [State::Discarded, 'discarded_at IS NOT NULL'];
        yield 'completed' => [State::Completed, 'completed_at IS NOT NULL AND discarded_at IS NULL'];
        yield 'claimed' => [State::Claimed, self::NOT_TERMINAL . ' AND consumed_till > NOW(6)'];
        yield 'scheduled' => [State::Scheduled, self::NOT_TERMINAL . ' AND ' . self::NOT_LEASED . ' AND available_at > NOW(6)'];
        yield 'claimable' => [State::Claimable, self::NOT_TERMINAL . ' AND ' . self::NOT_LEASED . ' AND available_at <= NOW(6)'];
    }

    #[DataProvider('statePredicates')]
    public function testEachStateIsAColumnPredicateNeverTheCase(State $state, string $predicate): void
    {
        self::assertSame($predicate, FilterCompiler::state($state));
        self::assertStringNotContainsString('CASE', $predicate);

        $sql = FilterCompiler::job(JobQuery::from(ClaimQuery::pool(new PoolName('p')))->states($state));

        self::assertSame('pool = ? AND ((' . $predicate . '))', $sql->text);
        self::assertSame(['p'], $sql->params);
    }

    public function testSeveralStatesAreOredInsideOneGroup(): void
    {
        $q = JobQuery::from(ClaimQuery::pool(new PoolName('p')))->states(State::Completed, State::Discarded);

        self::assertSame(
            'pool = ? AND ((completed_at IS NOT NULL AND discarded_at IS NULL) OR (discarded_at IS NOT NULL))',
            FilterCompiler::job($q)->text,
        );
    }

    public function testTimeWindowBoundsAreInclusiveBeforeAndExclusiveAfter(): void
    {
        $q = JobQuery::from(ClaimQuery::pool(new PoolName('p'))->namePrefix('a.'))
            ->availableAfter(Fixtures::at('2026-01-01 00:00:00'))
            ->availableBefore(Fixtures::at('2026-02-01 00:00:00'));

        $sql = FilterCompiler::job($q);

        self::assertSame('pool = ? AND name LIKE ? AND available_at <= ? AND available_at > ?', $sql->text);
        self::assertSame(['p', 'a.%', '2026-02-01 00:00:00.000000', '2026-01-01 00:00:00.000000'], $sql->params);
    }

    public function testDedupKeysCompileToAnInListOverEveryState(): void
    {
        $q = JobQuery::from(ClaimQuery::pool(new PoolName('p')))
            ->dedupKeys(DedupKey::fromString('k1'), DedupKey::fromString('k2'));

        $sql = FilterCompiler::job($q);

        self::assertSame('pool = ? AND dedup_key IN (?, ?)', $sql->text);
        self::assertSame(['p', 'k1', 'k2'], $sql->params);
    }

    public function testDedupKeysComeAfterStatesAndBeforeTheWindow(): void
    {
        $q = JobQuery::from(ClaimQuery::pool(new PoolName('p')))
            ->states(State::Discarded)
            ->dedupKeys(DedupKey::fromString('k'))
            ->availableBefore(Fixtures::at('2026-02-01 00:00:00'));

        self::assertSame(
            'pool = ? AND ((discarded_at IS NOT NULL)) AND dedup_key IN (?) AND available_at <= ?',
            FilterCompiler::job($q)->text,
        );
    }

    public function testStatesComeBetweenClaimCriteriaAndTheWindow(): void
    {
        $q = JobQuery::from(ClaimQuery::pool(new PoolName('p')))
            ->states(State::Discarded)
            ->availableBefore(Fixtures::at('2026-02-01 00:00:00'));

        self::assertSame('pool = ? AND ((discarded_at IS NOT NULL)) AND available_at <= ?', FilterCompiler::job($q)->text);
    }
}
