<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Postgres;

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
 * Every time comparison uses `now()` on the server; the only timestamps PHP sends are the ones the API defines as
 * data (`available_at`).
 */
final class QueueStatements
{
    /**
     * Push must respect two unique constraints (the PK and the partial dedup index) but a statement may carry only
     * one `ON CONFLICT` clause; both actions are "do nothing", so the untargeted form covers both and keeps push a
     * single statement. It cannot name which constraint fired, hence `RETURNING id` and the diff in `PushReports`.
     */
    public static function push(NewJob ...$jobs): Sql
    {
        $jobs = array_values($jobs);
        $column = static fn(callable $pick): string => PgLiteral::array(array_map($pick, $jobs));

        return new Sql(
            <<<SQL
                INSERT INTO jobs (id, name, pool, payload, available_at, dedup_key)
                SELECT n.id, n.name, n.pool, n.payload, n.available_at, n.dedup_key
                FROM unnest(?::uuid[], ?::text[], ?::text[], ?::jsonb[], ?::timestamptz[], ?::text[])
                AS n(id, name, pool, payload, available_at, dedup_key)
                ON CONFLICT DO NOTHING
                RETURNING id
                SQL,
            [
                $column(static fn(NewJob $j): string => $j->id->value),
                $column(static fn(NewJob $j): string => $j->name->value),
                $column(static fn(NewJob $j): string => $j->pool->value),
                $column(static fn(NewJob $j): string => PgLiteral::json($j->payload)),
                $column(static fn(NewJob $j): string => PgLiteral::timestamptz($j->availableAt)),
                $column(static fn(NewJob $j): string => $j->dedupKey->value),
            ],
        );
    }

    public static function claim(ClaimQuery $q, LeaseToken $token, WorkerId $by, Ttl $ttl): Sql
    {
        $filter = FilterCompiler::claim($q);

        return new Sql(
            <<<SQL
                UPDATE jobs j
                SET lease_token = ?, consumed_by = ?, consumed_till = now() + ?::interval,
                attempts = j.attempts + 1,
                abandoned_count = j.abandoned_count + (j.lease_token IS NOT NULL)::int,
                last_abandoned_by = CASE WHEN j.lease_token IS NOT NULL THEN j.consumed_by ELSE j.last_abandoned_by END,
                last_abandoned_at = CASE WHEN j.lease_token IS NOT NULL THEN j.consumed_till ELSE j.last_abandoned_at END,
                updated_at = now()
                FROM (SELECT id FROM jobs WHERE {$filter->text}
                AND completed_at IS NULL AND discarded_at IS NULL
                AND available_at <= now() AND (consumed_till IS NULL OR consumed_till <= now())
                ORDER BY available_at LIMIT ? FOR UPDATE SKIP LOCKED) picked
                WHERE j.id = picked.id
                RETURNING j.*
                SQL,
            [$token->value, $by->value, PgLiteral::interval($ttl), ...$filter->params, (string) $q->limit],
        );
    }

    /** @param list<JobId> $ids */
    public static function heartbeat(LeaseToken $token, Ttl $extend, array $ids): Sql
    {
        return new Sql(
            <<<SQL
                UPDATE jobs SET consumed_till = now() + ?::interval, updated_at = now()
                WHERE id = ANY(?::uuid[]) AND lease_token = ? RETURNING id
                SQL,
            [PgLiteral::interval($extend), self::ids($ids), $token->value],
        );
    }

    public static function settle(SettleBatch $batch, LeaseToken $token): Sql
    {
        if ($batch->outcome === null) {
            return self::settleMixed($batch, $token);
        }

        return self::settleUniform($batch->outcome, $batch->settlements[0], $batch->ids(), $token);
    }

    /**
     * Runs only on the rejection path, and only for terminating outcomes.
     *
     * @param list<JobId> $ids
     */
    public static function staleAck(array $ids, WorkerId $by): Sql
    {
        return new Sql(
            <<<SQL
                UPDATE jobs SET stale_ack_count = stale_ack_count + 1, last_stale_ack_by = ?, last_stale_ack_at = now()
                WHERE id = ANY(?::uuid[])
                SQL,
            [$by->value, self::ids($ids)],
        );
    }

    /** @param list<JobId> $ids */
    private static function settleUniform(Outcome $outcome, Settlement $inputs, array $ids, LeaseToken $token): Sql
    {
        [$set, $params] = self::uniformSet($outcome, $inputs);

        return new Sql(
            <<<SQL
                UPDATE jobs SET {$set}lease_token = NULL, consumed_till = NULL, updated_at = now()
                WHERE id = ANY(?::uuid[]) AND lease_token = ? RETURNING id
                SQL,
            [...$params, self::ids($ids), $token->value],
        );
    }

    /** @return array{string, list<string|null>} the outcome-specific `SET` prefix and its parameters */
    private static function uniformSet(Outcome $outcome, Settlement $inputs): array
    {
        return match ($outcome) {
            Outcome::Complete => ['completed_at = now(), ', []],
            Outcome::Discard => ['discarded_at = now(), last_fail_reason = ?::jsonb, ', [self::reason($inputs->reason)]],
            Outcome::Fail => [
                'available_at = ?::timestamptz, last_fail_reason = ?::jsonb,'
                . ' consecutive_failures = consecutive_failures + 1, consecutive_reschedules = 0, ',
                [self::timestamp($inputs->availableAt), self::reason($inputs->reason)],
            ],
            Outcome::Reschedule => [
                'available_at = ?::timestamptz, last_reschedule_reason = ?::jsonb,'
                . ' consecutive_reschedules = consecutive_reschedules + 1, consecutive_failures = 0, ',
                [self::timestamp($inputs->availableAt), self::reason($inputs->reason)],
            ],
            Outcome::Release => ['', []],
        };
    }

    private static function settleMixed(SettleBatch $batch, LeaseToken $token): Sql
    {
        $column = static fn(callable $pick): string => PgLiteral::array(array_map($pick, $batch->settlements));

        return new Sql(
            <<<SQL
                UPDATE jobs j SET
                completed_at = CASE WHEN s.outcome = 'complete' THEN now() ELSE j.completed_at END,
                discarded_at = CASE WHEN s.outcome = 'discard' THEN now() ELSE j.discarded_at END,
                available_at = CASE WHEN s.outcome IN ('fail', 'reschedule') THEN s.available_at ELSE j.available_at END,
                last_fail_reason = CASE WHEN s.outcome IN ('discard', 'fail') THEN s.reason ELSE j.last_fail_reason END,
                last_reschedule_reason = CASE WHEN s.outcome = 'reschedule' THEN s.reason ELSE j.last_reschedule_reason END,
                consecutive_failures = CASE WHEN s.outcome = 'fail' THEN j.consecutive_failures + 1
                WHEN s.outcome = 'reschedule' THEN 0 ELSE j.consecutive_failures END,
                consecutive_reschedules = CASE WHEN s.outcome = 'reschedule' THEN j.consecutive_reschedules + 1
                WHEN s.outcome = 'fail' THEN 0 ELSE j.consecutive_reschedules END,
                lease_token = NULL, consumed_till = NULL, updated_at = now()
                FROM unnest(?::uuid[], ?::text[], ?::jsonb[], ?::timestamptz[]) AS s(id, outcome, reason, available_at)
                WHERE j.id = s.id AND j.lease_token = ?
                RETURNING j.id
                SQL,
            [
                $column(static fn(Settlement $s): string => $s->job->id->value),
                $column(static fn(Settlement $s): string => $s->outcome->value),
                $column(static fn(Settlement $s): ?string => self::reason($s->reason)),
                $column(static fn(Settlement $s): ?string => self::timestamp($s->availableAt)),
                $token->value,
            ],
        );
    }

    /** @param list<JobId> $ids */
    private static function ids(array $ids): string
    {
        return PgLiteral::array(array_map(static fn(JobId $id): string => $id->value, $ids));
    }

    private static function reason(?Reason $reason): ?string
    {
        return $reason?->toJson();
    }

    private static function timestamp(?CarbonImmutable $at): ?string
    {
        return $at === null ? null : PgLiteral::timestamptz($at);
    }
}
