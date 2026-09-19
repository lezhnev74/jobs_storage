<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Mysql;

use Carbon\CarbonImmutable;
use Lezhnev74\Jobs\Driver\Support\SettleBatch;
use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Model\LeaseToken;
use Lezhnev74\Jobs\Model\NewJob;
use Lezhnev74\Jobs\Model\Outcome;
use Lezhnev74\Jobs\Model\Reason;
use Lezhnev74\Jobs\Model\Settlement;
use Lezhnev74\Jobs\Model\Ttl;
use Lezhnev74\Jobs\Model\WorkerId;
use Lezhnev74\Jobs\Query\ClaimQuery;

/**
 * Every time comparison uses `NOW(6)` on the server; the only timestamps PHP sends are the ones the API defines as
 * data (`available_at`). In every UPDATE the assignments that *read* a column precede the ones that overwrite it,
 * because MySQL evaluates a `SET` list left to right and later assignments see the new values.
 */
final class QueueStatements
{
    private const CLEAR_LEASE = 'lease_token = NULL, consumed_till = NULL, updated_at = NOW(6)';

    /** Qualified for the multi-table UPDATE. */
    private const CLEAR_LEASE_JOINED = 'j.lease_token = NULL, j.consumed_till = NULL, j.updated_at = NOW(6)';

    /**
     * `ON DUPLICATE KEY UPDATE id = id` already swallows a violation of *any* unique key, so the dedup index needs no
     * structural change here - only the extra column.
     */
    public static function push(NewJob ...$jobs): Sql
    {
        $jobs = array_values($jobs);
        $params = [];
        foreach ($jobs as $job) {
            $params[] = $job->id->value;
            $params[] = $job->name->value;
            $params[] = $job->pool->value;
            $params[] = MysqlLiteral::json($job->payload);
            $params[] = MysqlLiteral::datetime($job->availableAt);
            $params[] = $job->dedupKey->value;
        }
        $row = '(UUID_TO_BIN(?), ?, ?, CAST(? AS JSON), ?, ?)';

        $rows = implode(', ', array_fill(0, \count($jobs), $row));

        return new Sql(
            <<<SQL
                INSERT INTO jobs (id, name, pool, payload, available_at, dedup_key) VALUES {$rows}
                ON DUPLICATE KEY UPDATE id = id
                SQL,
            $params,
        );
    }

    /**
     * What already covers this batch: rows sharing an id, and non-terminal rows holding one of its `(pool, key)`
     * pairs. A plain consistent read - no locks, so it never waits on a claimed row - which is the point: the insert
     * that follows only carries the survivors, and so never touches a record another transaction has locked.
     *
     * The key half is an `IN` over pairs rather than one `OR` per job, so `jobs_dedup_uk` serves it as a set of
     * index lookups.
     *
     * @param list<NewJob> $jobs
     */
    public static function collisions(array $jobs): Sql
    {
        $ids = MysqlLiteral::idPlaceholders(\count($jobs));
        $pairs = implode(', ', array_fill(0, \count($jobs), '(?, ?)'));

        $params = [];
        foreach ($jobs as $job) {
            $params[] = $job->id->value;
        }
        foreach ($jobs as $job) {
            $params[] = $job->pool->value;
            $params[] = $job->dedupKey->value;
        }

        return new Sql(
            <<<SQL
                SELECT BIN_TO_UUID(id) AS id, pool, dedup_slot FROM jobs
                WHERE id IN ({$ids}) OR (pool, dedup_slot) IN ({$pairs})
                SQL,
            $params,
        );
    }

    /**
     * MySQL has no `RETURNING`, so which ids the insert stored is derived by probing the batch's primary keys after
     * it: an id the probe before the insert did not report, and this one does, is a row this push stored. A PK lookup
     * over one producer batch, run inside the push transaction so both probes see a consistent table.
     *
     * @param list<JobId> $ids
     */
    public static function existingIds(array $ids): Sql
    {
        $placeholders = MysqlLiteral::idPlaceholders(\count($ids));

        return new Sql(
            "SELECT BIN_TO_UUID(id) AS id FROM jobs WHERE id IN ({$placeholders})",
            MysqlLiteral::idParams($ids),
        );
    }

    public static function pick(ClaimQuery $q): Sql
    {
        $filter = FilterCompiler::claim($q);

        return new Sql(
            <<<SQL
                SELECT BIN_TO_UUID(id) AS id FROM jobs WHERE {$filter->text}
                AND completed_at IS NULL AND discarded_at IS NULL
                AND available_at <= NOW(6) AND (consumed_till IS NULL OR consumed_till <= NOW(6))
                ORDER BY available_at LIMIT ? FOR UPDATE SKIP LOCKED
                SQL,
            [...$filter->params, $q->limit],
        );
    }

    /**
     * Stamps the lease on rows already locked by `pick()`, counting the abandonment of whatever lease they still
     * carried. The three abandonment assignments read `lease_token`, `consumed_by` and `consumed_till`, so they must
     * precede the assignments that overwrite them.
     *
     * @param list<JobId> $ids
     */
    public static function lease(array $ids, LeaseToken $token, WorkerId $by, Ttl $ttl): Sql
    {
        $placeholders = MysqlLiteral::idPlaceholders(\count($ids));

        return new Sql(
            <<<SQL
                UPDATE jobs SET
                abandoned_count = abandoned_count + (lease_token IS NOT NULL),
                last_abandoned_by = IF(lease_token IS NOT NULL, consumed_by, last_abandoned_by),
                last_abandoned_at = IF(lease_token IS NOT NULL, consumed_till, last_abandoned_at),
                lease_token = UUID_TO_BIN(?), consumed_by = ?, consumed_till = NOW(6) + INTERVAL ? MICROSECOND,
                attempts = attempts + 1, updated_at = NOW(6)
                WHERE id IN ({$placeholders})
                SQL,
            [$token->value, $by->value, self::microseconds($ttl), ...MysqlLiteral::idParams($ids)],
        );
    }

    /** @param list<JobId> $ids */
    public static function hydrate(array $ids): Sql
    {
        $placeholders = MysqlLiteral::idPlaceholders(\count($ids));
        $columns = Columns::HYDRATION;

        return new Sql(
            <<<SQL
                SELECT {$columns} FROM jobs WHERE id IN ({$placeholders})
                ORDER BY available_at, id
                SQL,
            MysqlLiteral::idParams($ids),
        );
    }

    /**
     * Does not clear the token, so the rows it touched are exactly the rows `ackedIds()` finds afterwards.
     *
     * @param list<JobId> $ids
     */
    public static function heartbeat(LeaseToken $token, Ttl $extend, array $ids): Sql
    {
        $placeholders = MysqlLiteral::idPlaceholders(\count($ids));

        return new Sql(
            <<<SQL
                UPDATE jobs SET consumed_till = NOW(6) + INTERVAL ? MICROSECOND, updated_at = NOW(6)
                WHERE id IN ({$placeholders}) AND lease_token = UUID_TO_BIN(?)
                SQL,
            [self::microseconds($extend), ...MysqlLiteral::idParams($ids), $token->value],
        );
    }

    /**
     * The ids of the batch that still carry the token - the `AckReport`'s acked set. Settling clears the token, so on
     * that path this must run *before* the write, under `FOR UPDATE`. `SKIP LOCKED` is deliberately absent: a skipped
     * row would read as stale.
     *
     * @param list<JobId> $ids
     */
    public static function ackedIds(array $ids, LeaseToken $token, bool $lock): Sql
    {
        $placeholders = MysqlLiteral::idPlaceholders(\count($ids));
        $forUpdate = $lock ? ' FOR UPDATE' : '';

        return new Sql(
            <<<SQL
                SELECT BIN_TO_UUID(id) AS id FROM jobs
                WHERE id IN ({$placeholders}) AND lease_token = UUID_TO_BIN(?){$forUpdate}
                SQL,
            [...MysqlLiteral::idParams($ids), $token->value],
        );
    }

    /**
     * Applied to the ids `ackedIds()` returned, with the token fence kept in the `WHERE` as belt and braces.
     *
     * @param list<JobId> $acked
     */
    public static function settle(SettleBatch $batch, array $acked, LeaseToken $token): Sql
    {
        if ($batch->outcome === null) {
            return self::settleMixed($batch, $acked, $token);
        }

        return self::settleUniform($batch->outcome, $batch->settlements[0], $acked, $token);
    }

    /**
     * Runs in autocommit after the settle transaction committed, so a failure here never rolls a landed settlement
     * back.
     *
     * @param list<JobId> $ids
     */
    public static function staleAck(array $ids, WorkerId $by): Sql
    {
        $placeholders = MysqlLiteral::idPlaceholders(\count($ids));

        return new Sql(
            <<<SQL
                UPDATE jobs SET stale_ack_count = stale_ack_count + 1, last_stale_ack_by = ?, last_stale_ack_at = NOW(6)
                WHERE id IN ({$placeholders})
                SQL,
            [$by->value, ...MysqlLiteral::idParams($ids)],
        );
    }

    /** @param list<JobId> $acked */
    private static function settleUniform(Outcome $outcome, Settlement $inputs, array $acked, LeaseToken $token): Sql
    {
        [$set, $params] = self::uniformSet($outcome, $inputs);

        $placeholders = MysqlLiteral::idPlaceholders(\count($acked));
        $clearLease = self::CLEAR_LEASE;

        return new Sql(
            <<<SQL
                UPDATE jobs SET {$set}{$clearLease}
                WHERE id IN ({$placeholders}) AND lease_token = UUID_TO_BIN(?)
                SQL,
            [...$params, ...MysqlLiteral::idParams($acked), $token->value],
        );
    }

    /** @return array{string, list<string|null>} the outcome-specific `SET` prefix and its parameters */
    private static function uniformSet(Outcome $outcome, Settlement $inputs): array
    {
        return match ($outcome) {
            Outcome::Complete => ['completed_at = NOW(6), ', []],
            Outcome::Discard => ['discarded_at = NOW(6), last_fail_reason = CAST(? AS JSON), ', [self::reason($inputs->reason)]],
            Outcome::Fail => [
                'available_at = ?, last_fail_reason = CAST(? AS JSON),'
                . ' consecutive_failures = consecutive_failures + 1, consecutive_reschedules = 0, ',
                [self::timestamp($inputs->availableAt), self::reason($inputs->reason)],
            ],
            Outcome::Reschedule => [
                'available_at = ?, last_reschedule_reason = CAST(? AS JSON),'
                . ' consecutive_reschedules = consecutive_reschedules + 1, consecutive_failures = 0, ',
                [self::timestamp($inputs->availableAt), self::reason($inputs->reason)],
            ],
            Outcome::Release => ['', []],
        };
    }

    /**
     * A table value constructor joined into a multi-table UPDATE. `ELSE j.<col>` keeps untouched columns
     * byte-identical and covers `release` for free. Only the acked ids are sent, so a stale row is never in the join.
     *
     * @param list<JobId> $acked
     */
    private static function settleMixed(SettleBatch $batch, array $acked, LeaseToken $token): Sql
    {
        $ackedSet = array_fill_keys(MysqlLiteral::idParams($acked), true);
        $rows = [];
        $params = [];
        foreach ($batch->settlements as $settlement) {
            if (!isset($ackedSet[$settlement->job->id->value])) {
                continue;
            }
            $rows[] = 'ROW(UUID_TO_BIN(?), ?, CAST(? AS JSON), ?)';
            $params[] = $settlement->job->id->value;
            $params[] = $settlement->outcome->value;
            $params[] = self::reason($settlement->reason);
            $params[] = self::timestamp($settlement->availableAt);
        }

        $values = implode(', ', $rows);
        $clearLease = self::CLEAR_LEASE_JOINED;

        return new Sql(
            <<<SQL
                UPDATE jobs j JOIN (VALUES {$values}) AS s(id, outcome, reason, available_at)
                ON s.id = j.id SET
                j.completed_at = IF(s.outcome = 'complete', NOW(6), j.completed_at),
                j.discarded_at = IF(s.outcome = 'discard', NOW(6), j.discarded_at),
                j.available_at = IF(s.outcome IN ('fail', 'reschedule'), s.available_at, j.available_at),
                j.last_fail_reason = IF(s.outcome IN ('discard', 'fail'), s.reason, j.last_fail_reason),
                j.last_reschedule_reason = IF(s.outcome = 'reschedule', s.reason, j.last_reschedule_reason),
                j.consecutive_failures = CASE s.outcome
                WHEN 'fail' THEN j.consecutive_failures + 1 WHEN 'reschedule' THEN 0 ELSE j.consecutive_failures END,
                j.consecutive_reschedules = CASE s.outcome
                WHEN 'reschedule' THEN j.consecutive_reschedules + 1 WHEN 'fail' THEN 0 ELSE j.consecutive_reschedules END,
                {$clearLease}
                WHERE j.lease_token = UUID_TO_BIN(?)
                SQL,
            [...$params, $token->value],
        );
    }

    /** Microseconds survive a bound parameter unambiguously; `INTERVAL ? SECOND` rounds to whole seconds. */
    private static function microseconds(Ttl $ttl): int
    {
        return $ttl->seconds * 1_000_000;
    }

    private static function reason(?Reason $reason): ?string
    {
        return $reason?->toJson();
    }

    private static function timestamp(?CarbonImmutable $at): ?string
    {
        return $at === null ? null : MysqlLiteral::datetime($at);
    }
}
