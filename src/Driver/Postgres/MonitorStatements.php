<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Postgres;

use Carbon\CarbonImmutable;
use Lezhnev74\Jobs\Model\Reason;
use Lezhnev74\Jobs\Query\GroupBy;
use Lezhnev74\Jobs\Query\JobQuery;
use Lezhnev74\Jobs\Query\Order;

/**
 * The janitor verbs pick their rows through a `SKIP LOCKED` subselect capped at `JobQuery::$limit`, so an ops sweep
 * never waits on a claim in flight and the caller loops over batches.
 */
final class MonitorStatements
{
    /** The inferred-state expression, for `GROUP BY` only - a `WHERE` uses column predicates so partial indexes match. */
    public const STATE_CASE = 'CASE'
        . " WHEN discarded_at IS NOT NULL THEN 'discarded'"
        . " WHEN completed_at IS NOT NULL THEN 'completed'"
        . " WHEN consumed_till > now() THEN 'claimed'"
        . " WHEN available_at > now() THEN 'scheduled'"
        . " ELSE 'claimable' END";

    public static function get(string $id): Sql
    {
        return new Sql('SELECT * FROM jobs WHERE id = ?', [$id]);
    }

    /**
     * Spells out the `jobs_dedup_uk` predicate verbatim - `(pool, dedup_key)` plus both terminal markers being null -
     * so the partial unique index serves the lookup directly. At most one row can match.
     */
    public static function find(string $pool, string $key): Sql
    {
        return new Sql(
            'SELECT * FROM jobs WHERE pool = ? AND dedup_key = ? AND completed_at IS NULL AND discarded_at IS NULL',
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

        return new Sql(
            'SELECT * FROM jobs WHERE ' . $filter->text . ' ' . $cursor->text
            . ' ORDER BY ' . self::order($q->orderBy) . ' ' . $limit->text,
            [...$filter->params, ...$cursor->params, ...$limit->params],
        );
    }

    public static function count(JobQuery $q): Sql
    {
        $filter = FilterCompiler::job($q);

        return new Sql('SELECT count(*) FROM jobs WHERE ' . $filter->text, $filter->params);
    }

    /** The group key is NULL for rows without a value on the axis. */
    public static function aggregate(JobQuery $q, GroupBy $g): Sql
    {
        $filter = FilterCompiler::job($q);

        return new Sql(
            'SELECT ' . self::groupExpression($g) . ' AS grp, count(*) AS cnt FROM jobs WHERE ' . $filter->text . ' GROUP BY 1',
            $filter->params,
        );
    }

    /** Skips rows under a live lease. */
    public static function delete(JobQuery $q): Sql
    {
        $picked = self::picked($q, 'AND (consumed_till IS NULL OR consumed_till <= now())');

        return new Sql('DELETE FROM jobs WHERE id IN (' . $picked->text . ')', $picked->params);
    }

    public static function deleteIncludingLeased(JobQuery $q): Sql
    {
        $picked = self::picked($q, '');

        return new Sql('DELETE FROM jobs WHERE id IN (' . $picked->text . ')', $picked->params);
    }

    /** Clears the lease and the terminal markers but leaves every counter as it is. */
    public static function requeue(JobQuery $q, CarbonImmutable $at, Reason $why): Sql
    {
        $picked = self::picked($q, '');

        return new Sql(
            <<<SQL
                UPDATE jobs SET available_at = ?::timestamptz, last_reschedule_reason = ?::jsonb,
                lease_token = NULL, consumed_till = NULL, completed_at = NULL, discarded_at = NULL, updated_at = now()
                WHERE id IN ({$picked->text})
                SQL,
            [PgLiteral::timestamptz($at), $why->toJson(), ...$picked->params],
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
            GroupBy::NamePrefix => "split_part(name, '.', 1)",
            GroupBy::ReasonCode => "last_fail_reason->>'code'",
            GroupBy::Worker => 'consumed_by',
        };
    }

    /**
     * `$extraCondition` and the `LIMIT` fragment carry no padding of their own - `Sql` trims what it is given - so
     * they are joined with an explicit space and an empty fragment collapses harmlessly.
     */
    private static function picked(JobQuery $q, string $extraCondition): Sql
    {
        $filter = FilterCompiler::job($q);
        $limit = self::limit($q);

        return new Sql(
            'SELECT id FROM jobs WHERE ' . $filter->text . ' ' . $extraCondition . ' ' . $limit->text
            . ' FOR UPDATE SKIP LOCKED',
            [...$filter->params, ...$limit->params],
        );
    }

    /** Keyset paging over the PK: an index range scan, where an OFFSET would re-read every skipped row. */
    private static function cursor(JobQuery $q): Sql
    {
        return $q->after === null
            ? new Sql('')
            : new Sql('AND id ' . $q->orderBy->cursorComparison() . ' ?::uuid', [$q->after->value]);
    }

    private static function limit(JobQuery $q): Sql
    {
        return $q->limit === null ? new Sql('') : new Sql('LIMIT ?', [(string) $q->limit]);
    }
}
