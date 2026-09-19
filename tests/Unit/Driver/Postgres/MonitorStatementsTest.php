<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Driver\Postgres;

use Lezhnev74\Jobs\Driver\Postgres\MonitorStatements;
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
    private const ID = '0190e2a0-0000-7000-8000-00000000000a';

    public function testGetIsByPrimaryKey(): void
    {
        $sql = MonitorStatements::get(self::ID);

        self::assertSame('SELECT * FROM jobs WHERE id = ?', $sql->text);
        self::assertSame([self::ID], $sql->params);
    }

    public function testFindSpellsOutThePartialIndexPredicate(): void
    {
        $sql = MonitorStatements::find('emails', 'invoice:2026-09:acme');

        self::assertSame(
            'SELECT * FROM jobs WHERE pool = ? AND dedup_key = ? AND completed_at IS NULL AND discarded_at IS NULL',
            $sql->text,
        );
        self::assertSame(['emails', 'invoice:2026-09:acme'], $sql->params);
    }

    public function testReadDefaultsToIdOrderWithoutALimit(): void
    {
        $sql = MonitorStatements::read(self::query());

        self::assertSame('SELECT * FROM jobs WHERE pool = ? ORDER BY id', $sql->text);
        self::assertSame(['p'], $sql->params);
    }

    public function testReadAppliesOrderAndLimitAfterTheFilter(): void
    {
        $q = self::query()->states(State::Claimable)->orderBy(Order::AvailableAtDesc)->limit(50);

        $sql = MonitorStatements::read($q);

        self::assertStringStartsWith('SELECT * FROM jobs WHERE pool = ? AND ((completed_at IS NULL', $sql->text);
        self::assertStringEndsWith(' ORDER BY available_at DESC, id DESC LIMIT ?', $sql->text);
        self::assertSame(['p', '50'], $sql->params);
    }

    /** @return iterable<string, array{Order, string}> */
    public static function orders(): iterable
    {
        yield 'id asc' => [Order::IdAsc, 'id'];
        yield 'id desc' => [Order::IdDesc, 'id DESC'];
        yield 'available_at asc' => [Order::AvailableAtAsc, 'available_at, id'];
        yield 'available_at desc' => [Order::AvailableAtDesc, 'available_at DESC, id DESC'];
    }

    public function testReadCompilesTheCursorAsAKeysetPredicateOnTheFilteredSide(): void
    {
        $q = self::query()->states(State::Claimable)->after(JobId::fromString(self::ID))->limit(2);

        $sql = MonitorStatements::read($q);

        self::assertStringEndsWith(' AND id > ?::uuid ORDER BY id LIMIT ?', $sql->text);
        self::assertStringNotContainsString('OFFSET', $sql->text);
        self::assertSame(['p', self::ID, '2'], $sql->params);
    }

    public function testADescendingCursorWalksBackwards(): void
    {
        $q = self::query()->orderBy(Order::IdDesc)->after(JobId::fromString(self::ID));

        self::assertSame('SELECT * FROM jobs WHERE pool = ? AND id < ?::uuid ORDER BY id DESC', MonitorStatements::read($q)->text);
    }

    public function testTheJanitorVerbsAndAggregatesIgnoreTheCursor(): void
    {
        $q = self::query()->after(JobId::fromString(self::ID));

        foreach ([MonitorStatements::count($q), MonitorStatements::aggregate($q, GroupBy::Pool), MonitorStatements::delete($q), MonitorStatements::deleteIncludingLeased($q), MonitorStatements::requeue($q, Fixtures::at('2026-09-11 12:00:00'), new Reason(['code' => 'ops']))] as $sql) {
            self::assertStringNotContainsString('?::uuid', $sql->text);
            self::assertNotContains(self::ID, $sql->params);
        }
    }

    #[DataProvider('orders')]
    public function testEveryOrderHasAClause(Order $order, string $clause): void
    {
        self::assertSame($clause, MonitorStatements::order($order));
    }

    public function testCountIgnoresOrderAndLimit(): void
    {
        $sql = MonitorStatements::count(self::query()->orderBy(Order::IdDesc)->limit(3));

        self::assertSame('SELECT count(*) FROM jobs WHERE pool = ?', $sql->text);
        self::assertSame(['p'], $sql->params);
    }

    /** @return iterable<string, array{GroupBy, string}> */
    public static function groupExpressions(): iterable
    {
        yield 'state' => [GroupBy::State, MonitorStatements::STATE_CASE];
        yield 'pool' => [GroupBy::Pool, 'pool'];
        yield 'name prefix' => [GroupBy::NamePrefix, "split_part(name, '.', 1)"];
        yield 'reason code' => [GroupBy::ReasonCode, "last_fail_reason->>'code'"];
        yield 'worker' => [GroupBy::Worker, 'consumed_by'];
    }

    #[DataProvider('groupExpressions')]
    public function testAggregateGroupsOnTheAxisExpression(GroupBy $axis, string $expression): void
    {
        $sql = MonitorStatements::aggregate(self::query()->limit(3), $axis);

        self::assertSame('SELECT ' . $expression . ' AS grp, count(*) AS cnt FROM jobs WHERE pool = ? GROUP BY 1', $sql->text);
        self::assertSame(['p'], $sql->params);
    }

    public function testStateCaseFollowsTheInferenceOrderAndIsOnlyUsedForGrouping(): void
    {
        self::assertStringStartsWith(
            "SELECT CASE WHEN discarded_at IS NOT NULL THEN 'discarded' WHEN completed_at IS NOT NULL THEN 'completed'"
            . " WHEN consumed_till > now() THEN 'claimed' WHEN available_at > now() THEN 'scheduled' ELSE 'claimable' END AS grp",
            MonitorStatements::aggregate(self::query(), GroupBy::State)->text,
        );
        self::assertStringNotContainsString('CASE', MonitorStatements::aggregate(self::query()->states(State::Claimed), GroupBy::Pool)->text);
    }

    public function testDeleteSkipsLiveLeasesThroughASkipLockedSubselect(): void
    {
        $sql = MonitorStatements::delete(self::query()->states(State::Discarded)->limit(1000));

        self::assertSame(
            'DELETE FROM jobs WHERE id IN (SELECT id FROM jobs WHERE pool = ? AND ((discarded_at IS NOT NULL))'
            . ' AND (consumed_till IS NULL OR consumed_till <= now()) LIMIT ? FOR UPDATE SKIP LOCKED)',
            $sql->text,
        );
        self::assertSame(['p', '1000'], $sql->params);
    }

    public function testDeleteIncludingLeasedDropsTheLeaseGuardAndTheLimitWhenUnset(): void
    {
        $sql = MonitorStatements::deleteIncludingLeased(self::query());

        self::assertSame('DELETE FROM jobs WHERE id IN (SELECT id FROM jobs WHERE pool = ? FOR UPDATE SKIP LOCKED)', $sql->text);
        self::assertSame(['p'], $sql->params);
    }

    public function testRequeueClearsLeaseAndTerminalMarkersAndLeavesCountersAlone(): void
    {
        $sql = MonitorStatements::requeue(self::query()->limit(10), Fixtures::at('2026-09-11 12:00:00'), new Reason(['code' => 'ops']));

        self::assertSame(
            'UPDATE jobs SET available_at = ?::timestamptz, last_reschedule_reason = ?::jsonb,'
            . ' lease_token = NULL, consumed_till = NULL, completed_at = NULL, discarded_at = NULL, updated_at = now()'
            . ' WHERE id IN (SELECT id FROM jobs WHERE pool = ? LIMIT ? FOR UPDATE SKIP LOCKED)',
            $sql->text,
        );
        self::assertSame(['2026-09-11 12:00:00.000000+00:00', '{"code":"ops"}', 'p', '10'], $sql->params);
        self::assertStringNotContainsString('attempts', $sql->text);
        self::assertStringNotContainsString('consecutive', $sql->text);
    }

    private static function query(): JobQuery
    {
        return JobQuery::from(ClaimQuery::pool(new PoolName('p')));
    }
}
