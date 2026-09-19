<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Mysql;

use Carbon\CarbonImmutable;
use Lezhnev74\Jobs\Model\JobId;

/**
 * MySQL has no array parameters, so the placeholder lists - and with them the statement shape - vary with batch size.
 */
final class MysqlLiteral
{
    /**
     * `DATETIME(6)` has no offset and the session runs at `+00:00`, so the value must be rendered in UTC, never in
     * the caller's zone.
     */
    public static function datetime(CarbonImmutable $at): string
    {
        return $at->utc()->format('Y-m-d H:i:s.u');
    }

    /**
     * An empty PHP array encodes as `{}`, never `[]`: the columns hold documents (`DEFAULT (JSON_OBJECT())`).
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

    public static function idPlaceholders(int $count): string
    {
        return implode(', ', array_fill(0, $count, 'UUID_TO_BIN(?)'));
    }

    /**
     * @param list<JobId> $ids
     * @return list<string>
     */
    public static function idParams(array $ids): array
    {
        return array_map(static fn(JobId $id): string => $id->value, $ids);
    }
}
