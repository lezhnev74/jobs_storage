<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Postgres;

use Lezhnev74\Jobs\Driver\Support\LikePattern;
use Lezhnev74\Jobs\Query\ClaimQuery;
use Lezhnev74\Jobs\Query\JobQuery;
use Lezhnev74\Jobs\Query\State;

/**
 * Compiles query objects to `WHERE` fragments. Payload criteria become one containment (`@>`) test, so
 * `$.customer.region = 'eu'` is `payload @> '{"customer":{"region":"eu"}}'`. Every requested state is spelled out as
 * its own column predicate so partial indexes still match - the state `CASE` never appears in a `WHERE`.
 *
 * `dedupKeys` compiles to a plain `dedup_key IN (...)`: the dedup index is partial (live rows only) and this filter
 * spans every state, so it is a heap filter by design.
 */
final class FilterCompiler
{
    private const NOT_TERMINAL = 'completed_at IS NULL AND discarded_at IS NULL';
    private const NOT_LEASED = '(consumed_till IS NULL OR consumed_till <= now())';

    public static function claim(ClaimQuery $q): Sql
    {
        $conditions = ['pool = ?'];
        $params = [$q->pool->value];

        if ($q->namePrefix !== null) {
            $conditions[] = 'name LIKE ?';
            $params[] = LikePattern::prefix($q->namePrefix);
        }
        if ($q->payload !== []) {
            $conditions[] = 'payload @> ?::jsonb';
            $params[] = PgLiteral::json(self::containment($q->payload));
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
            $conditions[] = 'available_at <= ?::timestamptz';
            $params[] = PgLiteral::timestamptz($q->availableBefore);
        }
        if ($q->availableAfter !== null) {
            $conditions[] = 'available_at > ?::timestamptz';
            $params[] = PgLiteral::timestamptz($q->availableAfter);
        }

        return new Sql(implode(' AND ', $conditions), $params);
    }

    /** The column predicate of one inferred state, evaluated on the database clock. */
    public static function state(State $state): string
    {
        return match ($state) {
            State::Discarded => 'discarded_at IS NOT NULL',
            State::Completed => 'completed_at IS NOT NULL AND discarded_at IS NULL',
            State::Claimed => self::NOT_TERMINAL . ' AND consumed_till > now()',
            State::Scheduled => self::NOT_TERMINAL . ' AND ' . self::NOT_LEASED . ' AND available_at > now()',
            State::Claimable => self::NOT_TERMINAL . ' AND ' . self::NOT_LEASED . ' AND available_at <= now()',
        };
    }

    /** @param non-empty-list<State> $states */
    private static function states(array $states): string
    {
        $predicates = array_map(static fn(State $s): string => '(' . self::state($s) . ')', $states);

        return '(' . implode(' OR ', $predicates) . ')';
    }

    /**
     * Merges `$.a.b => v` paths into one nested document so a single containment test covers all of them.
     *
     * @param array<string, mixed> $paths
     * @return array<string, mixed>
     */
    private static function containment(array $paths): array
    {
        $document = [];
        foreach ($paths as $path => $value) {
            self::assign($document, explode('.', substr($path, 2)), $value);
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     * @param list<string> $segments
     */
    private static function assign(array &$document, array $segments, mixed $value): void
    {
        $key = array_shift($segments);
        if ($segments === []) {
            $document[$key] = $value;

            return;
        }

        $child = \is_array($document[$key] ?? null) ? $document[$key] : [];
        self::assign($child, $segments, $value);
        $document[$key] = $child;
    }
}
