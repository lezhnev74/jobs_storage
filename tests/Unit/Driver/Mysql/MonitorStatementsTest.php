<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Driver\Mysql;

use Lezhnev74\Jobs\Driver\Mysql\MonitorStatements;
use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Model\PoolName;
use Lezhnev74\Jobs\Model\Reason;
use Lezhnev74\Jobs\Query\ClaimQuery;
use Lezhnev74\Jobs\Query\GroupBy;
use Lezhnev74\Jobs\Query\JobQuery;
use Lezhnev74\Jobs\Query\Order;
use Lezhnev74\Jobs\Query\State;
use Lezhnev74\Jobs\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MonitorStatements::class)]
final class MonitorStatementsTest extends TestCase
{
    private const ID_A = '0190e2a0-0000-7000-8000-00000000000a';

    public function testGetSelectsTheHydrationColumnsByPrimaryKey(): void
    {
        $sql = MonitorStatements::get(JobId::fromString(self::ID_A));

        self::assertStringStartsWith('SELECT BIN_TO_UUID(id) AS id,', $sql->text);
        self::assertStringEndsWith(' FROM jobs WHERE id = UUID_TO_BIN(?)', $sql->text);
        self::assertSame([self::ID_A], $sql->params);
    }

    public function testFindMatchesTheGeneratedSlotSoTerminalRowsFallOut(): void
    {
        $sql = MonitorStatements::find('emails', 'invoice:2026-09:acme');

        self::assertStringStartsWith('SELECT BIN_TO_UUID(id) AS id,', $sql->text);
        self::assertStringEndsWith(' FROM jobs WHERE pool = ? AND dedup_slot = ?', $sql->text);
        self::assertSame(['emails', 'invoice:2026-09:acme'], $sql->params);
    }

    public function testReadOrdersAndLimitsWithAnIntegerBoundLimit(): void
    {
        $q = self::query()->orderBy(Order::AvailableAtDesc)->limit(50);

        $sql = MonitorStatements::read($q);

        self::assertStringEndsWith(' FROM jobs WHERE pool = ? ORDER BY available_at DESC, id DESC LIMIT ?', $sql->text);
        self::assertSame(['p', 50], $sql->params);
    }

    public function testReadWithoutALimitOmitsTheClause(): void
    {
        self::assertStringEndsWith(' WHERE pool = ? ORDER BY id', MonitorStatements::read(self::query())->text);
        self::assertSame(['p'], MonitorStatements::read(self::query())->params);
    }

    public function testReadCompilesTheCursorInTheBinaryIdDomain(): void
    {
        $q = self::query()->after(JobId::fromString(self::ID_A))->limit(2);

        $sql = MonitorStatements::read($q);

        self::assertStringEndsWith(' WHERE pool = ? AND id > UUID_TO_BIN(?) ORDER BY id LIMIT ?', $sql->text);
        self::assertStringNotContainsString('OFFSET', $sql->text);
        self::assertSame(['p', self::ID_A, 2], $sql->params);
    }

    public function testADescendingCursorWalksBackwards(): void
    {
        $q = self::query()->orderBy(Order::IdDesc)->after(JobId::fromString(self::ID_A));

        self::assertStringEndsWith(' WHERE pool = ? AND id < UUID_TO_BIN(?) ORDER BY id DESC', MonitorStatements::read($q)->text);
    }

    public function testTheJanitorPickAndAggregatesIgnoreTheCursor(): void
    {
        $q = self::query()->after(JobId::fromString(self::ID_A));

        foreach ([MonitorStatements::count($q), MonitorStatements::aggregate($q, GroupBy::Pool), MonitorStatements::pick($q, true), MonitorStatements::pick($q, false)] as $sql) {
            self::assertStringNotContainsString('UUID_TO_BIN', $sql->text);
            self::assertNotContains(self::ID_A, $sql->params);
        }
    }

    public function testCountIgnoresOrderAndLimit(): void
    {
        $sql = MonitorStatements::count(self::query()->orderBy(Order::IdDesc)->limit(5));

        self::assertSame('SELECT COUNT(*) FROM jobs WHERE pool = ?', $sql->text);
        self::assertSame(['p'], $sql->params);
    }

    public function testAggregateGroupsByTheKeyExpressionAndIgnoresOrderAndLimit(): void
    {
        $sql = MonitorStatements::aggregate(self::query()->limit(5), GroupBy::Pool);

        self::assertSame('SELECT pool AS grp, COUNT(*) AS cnt FROM jobs WHERE pool = ? GROUP BY grp', $sql->text);
        self::assertSame(['p'], $sql->params);
    }

    /** @return iterable<string, array{GroupBy, string}> */
    public static function groupExpressions(): iterable
    {
        yield 'pool' => [GroupBy::Pool, 'pool'];
        yield 'namePrefix' => [GroupBy::NamePrefix, "SUBSTRING_INDEX(name, '.', 1)"];
        yield 'reasonCode' => [GroupBy::ReasonCode, "last_fail_reason->>'\$.code'"];
        yield 'worker' => [GroupBy::Worker, 'consumed_by'];
    }

    #[DataProvider('groupExpressions')]
    public function testEachAxisHasItsOwnKeyExpression(GroupBy $g, string $expression): void
    {
        self::assertSame($expression, MonitorStatements::groupExpression($g));
    }

    public function testStateIsTheOnlyAxisCompiledAsACase(): void
    {
        self::assertSame(MonitorStatements::STATE_CASE, MonitorStatements::groupExpression(GroupBy::State));
        self::assertStringContainsString("WHEN consumed_till > NOW(6) THEN 'claimed'", MonitorStatements::STATE_CASE);
    }

    /** @return iterable<string, array{Order, string}> */
    public static function orders(): iterable
    {
        yield 'idAsc' => [Order::IdAsc, 'id'];
        yield 'idDesc' => [Order::IdDesc, 'id DESC'];
        yield 'availableAtAsc' => [Order::AvailableAtAsc, 'available_at, id'];
        yield 'availableAtDesc' => [Order::AvailableAtDesc, 'available_at DESC, id DESC'];
    }

    #[DataProvider('orders')]
    public function testEveryOrderIsTotal(Order $order, string $clause): void
    {
        self::assertSame($clause, MonitorStatements::order($order));
    }

    public function testPickSkipsLiveLeasesForDeleteButNotForTheOtherVerbs(): void
    {
        $q = self::query()->states(State::Completed)->limit(1000);
        $tail = ' FOR UPDATE SKIP LOCKED';

        $skipping = MonitorStatements::pick($q, skipLeased: true);
        $including = MonitorStatements::pick($q, skipLeased: false);

        self::assertSame(
            'SELECT BIN_TO_UUID(id) AS id FROM jobs WHERE pool = ? AND ((completed_at IS NOT NULL AND discarded_at IS NULL))'
            . ' AND (consumed_till IS NULL OR consumed_till <= NOW(6)) LIMIT ?' . $tail,
            $skipping->text,
        );
        self::assertStringNotContainsString('consumed_till', substr($including->text, 0, (int) strpos($including->text, 'LIMIT')));
        self::assertSame(['p', 1000], $including->params);
    }

    public function testDeleteGoesByPrimaryKey(): void
    {
        $sql = MonitorStatements::delete([JobId::fromString(self::ID_A)]);

        self::assertSame('DELETE FROM jobs WHERE id IN (UUID_TO_BIN(?))', $sql->text);
        self::assertSame([self::ID_A], $sql->params);
    }

    public function testRequeueClearsLeaseAndTerminalMarkersAndTouchesNoCounter(): void
    {
        $sql = MonitorStatements::requeue(
            [JobId::fromString(self::ID_A)],
            Fixtures::at('2026-02-01 00:00:00'),
            new Reason(['code' => 'ops']),
        );

        self::assertSame(
            'UPDATE jobs SET available_at = ?, last_reschedule_reason = CAST(? AS JSON),'
            . ' lease_token = NULL, consumed_till = NULL, completed_at = NULL, discarded_at = NULL, updated_at = NOW(6)'
            . ' WHERE id IN (UUID_TO_BIN(?))',
            $sql->text,
        );
        self::assertSame(['2026-02-01 00:00:00.000000', '{"code":"ops"}', self::ID_A], $sql->params);
        self::assertStringNotContainsString('attempts', $sql->text);
        self::assertStringNotContainsString('consecutive', $sql->text);
    }

    private static function query(): JobQuery
    {
        return JobQuery::from(ClaimQuery::pool(new PoolName('p')));
    }
}
