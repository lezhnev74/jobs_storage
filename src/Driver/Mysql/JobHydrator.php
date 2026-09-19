<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Mysql;

use Carbon\CarbonImmutable;
use Lezhnev74\Jobs\Model\DedupKey;
use Lezhnev74\Jobs\Model\Incident;
use Lezhnev74\Jobs\Model\Job;
use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Model\JobName;
use Lezhnev74\Jobs\Model\Lease;
use Lezhnev74\Jobs\Model\PoolName;
use Lezhnev74\Jobs\Model\RetryState;
use Lezhnev74\Jobs\Model\Telemetry;
use Lezhnev74\Jobs\Model\Termination;
use UnexpectedValueException;

/**
 * `consumed_by` outlives the lease in the row: while leased it surfaces as `Lease::$by`, afterwards as
 * `Telemetry::$lastClaimedBy`.
 *
 * Every value arrives as a string - native prepares return `DATETIME` and `JSON` as text, and the id columns are
 * already `BIN_TO_UUID`-ed by the select list. `DATETIME(6)` carries no offset and the session runs at `+00:00`, so
 * timestamps are parsed as UTC rather than in PHP's default zone.
 */
final class JobHydrator
{
    /** @param array<string, mixed> $row */
    public static function hydrate(array $row): Job
    {
        $lease = self::lease($row);

        return new Job(
            id: JobId::fromString(self::string($row, 'id')),
            name: new JobName(self::string($row, 'name')),
            pool: new PoolName(self::string($row, 'pool')),
            payload: self::json($row, 'payload') ?? [],
            dedupKey: DedupKey::fromString(self::string($row, 'dedup_key')),
            availableAt: self::time($row, 'available_at'),
            termination: self::termination($row),
            lease: $lease,
            retry: self::retry($row),
            telemetry: self::telemetry($row, $lease),
            createdAt: self::time($row, 'created_at'),
            updatedAt: self::time($row, 'updated_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private static function termination(array $row): ?Termination
    {
        $discardedAt = self::nullableTime($row, 'discarded_at');
        if ($discardedAt !== null) {
            return Termination::discarded($discardedAt);
        }
        $completedAt = self::nullableTime($row, 'completed_at');

        return $completedAt === null ? null : Termination::completed($completedAt);
    }

    /** @param array<string, mixed> $row */
    private static function lease(array $row): ?Lease
    {
        $till = self::nullableTime($row, 'consumed_till');

        return $till === null ? null : new Lease(self::string($row, 'consumed_by'), $till);
    }

    /** @param array<string, mixed> $row */
    private static function retry(array $row): RetryState
    {
        return new RetryState(
            attempts: self::int($row, 'attempts'),
            consecutiveFailures: self::int($row, 'consecutive_failures'),
            consecutiveReschedules: self::int($row, 'consecutive_reschedules'),
            lastFailReason: self::json($row, 'last_fail_reason'),
            lastRescheduleReason: self::json($row, 'last_reschedule_reason'),
        );
    }

    /** @param array<string, mixed> $row */
    private static function telemetry(array $row, ?Lease $lease): Telemetry
    {
        return new Telemetry(
            abandonment: self::incident($row, 'abandoned'),
            staleAck: self::incident($row, 'stale_ack'),
            lastClaimedBy: $lease === null ? self::nullableString($row, 'consumed_by') : null,
        );
    }

    /** @param array<string, mixed> $row */
    private static function incident(array $row, string $prefix): ?Incident
    {
        $count = self::int($row, $prefix . '_count');
        if ($count === 0) {
            return null;
        }

        return new Incident($count, self::string($row, 'last_' . $prefix . '_by'), self::time($row, 'last_' . $prefix . '_at'));
    }

    /** @param array<string, mixed> $row */
    private static function string(array $row, string $column): string
    {
        return self::nullableString($row, $column) ?? throw self::unexpected($column, null);
    }

    /** @param array<string, mixed> $row */
    private static function nullableString(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;
        if ($value !== null && !\is_string($value)) {
            throw self::unexpected($column, $value);
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $column): int
    {
        $value = $row[$column] ?? null;
        if (!\is_int($value) && !(\is_string($value) && is_numeric($value))) {
            throw self::unexpected($column, $value);
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $row */
    private static function time(array $row, string $column): CarbonImmutable
    {
        return self::nullableTime($row, $column) ?? throw self::unexpected($column, null);
    }

    /** @param array<string, mixed> $row */
    private static function nullableTime(array $row, string $column): ?CarbonImmutable
    {
        $value = self::nullableString($row, $column);

        return $value === null ? null : CarbonImmutable::parse($value, 'UTC');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private static function json(array $row, string $column): ?array
    {
        $value = self::nullableString($row, $column);
        if ($value === null) {
            return null;
        }
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw self::unexpected($column, $value);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function unexpected(string $column, mixed $value): UnexpectedValueException
    {
        return new UnexpectedValueException(\sprintf('Unexpected value for column %s: %s', $column, get_debug_type($value)));
    }
}
