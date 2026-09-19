<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Mysql;

use Lezhnev74\Jobs\Driver\Support\LikePattern;
use Lezhnev74\Jobs\Query\ClaimQuery;
use Lezhnev74\Jobs\Query\JobQuery;
use Lezhnev74\Jobs\Query\State;

/**
 * `pool = ?` is the claim index's leading key. Payload criteria are one `JSON_CONTAINS(payload, ?, '$.path')` per
 * entry, because a candidate value at a path is a scalar and MySQL has no nested-document merge for it. It is not
 * indexed: it is a row filter over what the claim index already delivered.
 *
 * Every requested state becomes its own column predicate so the claim index still matches; the state `CASE` never
 * appears in a `WHERE`.
 *
 * `dedupKeys` compiles to a plain `dedup_key IN (...)`: the unique index covers `dedup_slot` (live rows only) and
 * this filter spans every state, so it is a heap filter by design.
 */
final class FilterCompiler
{
    private const NOT_TERMINAL = 'completed_at IS NULL AND discarded_at IS NULL';
    private const NOT_LEASED = '(consumed_till IS NULL OR consumed_till <= NOW(6))';

    public static function claim(ClaimQuery $q): Sql
    {
        $conditions = ['pool = ?'];
        $params = [$q->pool->value];

        if ($q->namePrefix !== null) {
            $conditions[] = 'name LIKE ?';
            $params[] = LikePattern::prefix($q->namePrefix);
        }
        foreach ($q->payload as $path => $value) {
            $conditions[] = 'JSON_CONTAINS(payload, CAST(? AS JSON), ?)';
            $params[] = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $params[] = $path;
        }

        return new Sql(implode(' AND ', $conditions), $params);
    }

    public static function job(JobQuery $q): Sql
    {
        $claim = self::claim($q->claim);
        $conditions = [$claim->text];
        $params = $claim->params;

        if ($q->states !== []) {
            $conditions[] = self::states($q->states);
        }
        if ($q->dedupKeys !== []) {
            $conditions[] = 'dedup_key IN (' . implode(', ', array_fill(0, \count($q->dedupKeys), '?')) . ')';
            foreach ($q->dedupKeys as $key) {
                $params[] = $key->value;
            }
        }
        if ($q->availableBefore !== null) {
            $conditions[] = 'available_at <= ?';
            $params[] = MysqlLiteral::datetime($q->availableBefore);
        }
        if ($q->availableAfter !== null) {
            $conditions[] = 'available_at > ?';
            $params[] = MysqlLiteral::datetime($q->availableAfter);
        }

        return new Sql(implode(' AND ', $conditions), $params);
    }

    /** The column predicate of one inferred state, evaluated on the database clock. */
    public static function state(State $state): string
    {
        return match ($state) {
            State::Discarded => 'discarded_at IS NOT NULL',
            State::Completed => 'completed_at IS NOT NULL AND discarded_at IS NULL',
            State::Claimed => self::NOT_TERMINAL . ' AND consumed_till > NOW(6)',
            State::Scheduled => self::NOT_TERMINAL . ' AND ' . self::NOT_LEASED . ' AND available_at > NOW(6)',
            State::Claimable => self::NOT_TERMINAL . ' AND ' . self::NOT_LEASED . ' AND available_at <= NOW(6)',
        };
    }

    /** @param non-empty-list<State> $states */
    private static function states(array $states): string
    {
        $predicates = array_map(static fn(State $s): string => '(' . self::state($s) . ')', $states);

        return '(' . implode(' OR ', $predicates) . ')';
    }
}
