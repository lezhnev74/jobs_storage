<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Mysql;

use Carbon\CarbonImmutable;
use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Model\Reason;
use Lezhnev74\Jobs\Query\GroupBy;
use Lezhnev74\Jobs\Query\JobQuery;
use Lezhnev74\Jobs\Query\Order;

/**
 * The janitor verbs are split into a `SKIP LOCKED` lock-select plus a write by id, inside one transaction: MySQL
 * allows neither `DELETE ... FROM jobs WHERE id IN (SELECT ... FROM jobs)` (error 1093) nor `SKIP LOCKED` on a
 * `DELETE`. The split preserves the property that an ops sweep never waits on a claim in flight.
 */
final class MonitorStatements
{
    /** For `GROUP BY` only; a `WHERE` uses the per-state column predicates instead, so the claim index still matches. */
    public const STATE_CASE = 'CASE'
        . " WHEN discarded_at IS NOT NULL THEN 'discarded'"
        . " WHEN completed_at IS NOT NULL THEN 'completed'"
        . " WHEN consumed_till > NOW(6) THEN 'claimed'"
        . " WHEN available_at > NOW(6) THEN 'scheduled'"
        . " ELSE 'claimable' END";

    public static function get(JobId $id): Sql
    {
        return new Sql('SELECT ' . Columns::HYDRATION . ' FROM jobs WHERE id = UUID_TO_BIN(?)', [$id->value]);
    }

    /**
     * Matches on `dedup_slot`, the generated column that nulls itself on settlement, so the lookup is exactly the
     * `jobs_dedup_uk` unique key and terminal rows fall out for free. At most one row can match.
     */
    public static function find(string $pool, string $key): Sql
    {
        return new Sql(
            'SELECT ' . Columns::HYDRATION . ' FROM jobs WHERE pool = ? AND dedup_slot = ?',
            [$pool, $key],
        );
    }

    /**
     * The only statement honouring `JobQuery::$after`: a cursor is a position in a sort, and the janitor verbs and
     * the aggregates have no sort of their own.
     */
    public static function read(JobQuery $q): Sql
    {
        $filter = FilterCompiler::job($q);
        $cursor = self::cursor($q);
        $limit = self::limit($q);

        $columns = Columns::HYDRATION;
        $order = self::order($q->orderBy);

        return new Sql(
            <<<SQL
                SELECT {$columns} FROM jobs WHERE {$filter->text} {$cursor->text}
                ORDER BY {$order} {$limit->text}
                SQL,
            [...$filter->params, ...$cursor->params, ...$limit->params],
        );
    }

    public static function count(JobQuery $q): Sql
    {
        $filter = FilterCompiler::job($q);

        return new Sql('SELECT COUNT(*) FROM jobs WHERE ' . $filter->text, $filter->params);
    }

    public static function aggregate(JobQuery $q, GroupBy $g): Sql
    {
        $filter = FilterCompiler::job($q);

        return new Sql(
            'SELECT ' . self::groupExpression($g) . ' AS grp, COUNT(*) AS cnt FROM jobs WHERE ' . $filter->text
            . ' GROUP BY grp',
            $filter->params,
        );
    }

    /**
     * The optional fragments carry no padding of their own - `Sql` trims what it is given - so they are joined with
     * an explicit space and an empty one collapses harmlessly.
     */
    public static function pick(JobQuery $q, bool $skipLeased): Sql
    {
        $filter = FilterCompiler::job($q);
        $limit = self::limit($q);
        $leased = $skipLeased ? 'AND (consumed_till IS NULL OR consumed_till <= NOW(6))' : '';

        return new Sql(
            <<<SQL
                SELECT BIN_TO_UUID(id) AS id FROM jobs WHERE {$filter->text} {$leased} {$limit->text}
                FOR UPDATE SKIP LOCKED
                SQL,
            [...$filter->params, ...$limit->params],
        );
    }

    /** @param list<JobId> $ids */
    public static function delete(array $ids): Sql
    {
        return new Sql(
            'DELETE FROM jobs WHERE id IN (' . MysqlLiteral::idPlaceholders(\count($ids)) . ')',
            MysqlLiteral::idParams($ids),
        );
    }

    /**
     * Clears lease and terminal markers, leaves every counter as it is.
     *
     * @param list<JobId> $ids
     */
    public static function requeue(array $ids, CarbonImmutable $at, Reason $why): Sql
    {
        $placeholders = MysqlLiteral::idPlaceholders(\count($ids));

        return new Sql(
            <<<SQL
                UPDATE jobs SET available_at = ?, last_reschedule_reason = CAST(? AS JSON),
                lease_token = NULL, consumed_till = NULL, completed_at = NULL, discarded_at = NULL, updated_at = NOW(6)
                WHERE id IN ({$placeholders})
                SQL,
            [MysqlLiteral::datetime($at), $why->toJson(), ...MysqlLiteral::idParams($ids)],
        );
    }

    public static function order(Order $order): string
    {
        return match ($order) {
            Order::IdAsc => 'id',
            Order::IdDesc => 'id DESC',
            Order::AvailableAtAsc => 'available_at, id',
            Order::AvailableAtDesc => 'available_at DESC, id DESC',
        };
    }

    public static function groupExpression(GroupBy $g): string
    {
        return match ($g) {
            GroupBy::State => self::STATE_CASE,
            GroupBy::Pool => 'pool',
            GroupBy::NamePrefix => "SUBSTRING_INDEX(name, '.', 1)",
            GroupBy::ReasonCode => "last_fail_reason->>'$.code'",
            GroupBy::Worker => 'consumed_by',
        };
    }

    /**
     * Keyset paging over the PK. The column is `BINARY(16)`, so the cursor is compared in that domain - the id must
     * go through `UUID_TO_BIN` like every other id parameter, or the comparison would be against a text literal.
     */
    private static function cursor(JobQuery $q): Sql
    {
        return $q->after === null
            ? new Sql('')
            : new Sql('AND id ' . $q->orderBy->cursorComparison() . ' UUID_TO_BIN(?)', [$q->after->value]);
    }

    /** `LIMIT` takes no string, which is why the driver requires native prepares. */
    private static function limit(JobQuery $q): Sql
    {
        return $q->limit === null ? new Sql('') : new Sql('LIMIT ?', [$q->limit]);
    }
}
