<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Postgres;

use Carbon\CarbonImmutable;
use Lezhnev74\Jobs\Model\Ttl;

/**
 * Text renderings of PHP values that the statements cast server-side. PDO has no array binding, so array parameters
 * travel as PostgreSQL array literals (`{"a","b",NULL}`) cast with `?::uuid[]` and friends, which keeps one statement
 * shape for any batch size.
 */
final class PgLiteral
{
    /** @param list<string|null> $elements */
    public static function array(array $elements): string
    {
        return '{' . implode(',', array_map(self::element(...), $elements)) . '}';
    }

    /** The explicit offset makes the value exact regardless of the session timezone. */
    public static function timestamptz(CarbonImmutable $at): string
    {
        return $at->format('Y-m-d H:i:s.uP');
    }

    public static function interval(Ttl $ttl): string
    {
        return $ttl->seconds . ' seconds';
    }

    /**
     * An empty PHP array encodes as `{}`, never `[]`: the columns hold documents.
     *
     * @param array<mixed> $document
     */
    public static function json(array $document): string
    {
        if ($document === []) {
            return '{}';
        }

        return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function element(?string $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return '"' . strtr($value, ['\\' => '\\\\', '"' => '\\"']) . '"';
    }
}
