<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Mysql;

/**
 * `SELECT *` is never used: the two `BINARY(16)` columns must come back as canonical uuid text, since PHP compares
 * ids and tokens as strings. `BIN_TO_UUID` is called without the swap flag, matching `UUID_TO_BIN` on the way in.
 */
final class Columns
{
    public const HYDRATION = 'BIN_TO_UUID(id) AS id, available_at, consumed_till, completed_at, discarded_at,'
        . ' last_abandoned_at, last_stale_ack_at, created_at, updated_at,'
        . ' attempts, consecutive_failures, consecutive_reschedules, abandoned_count, stale_ack_count,'
        . ' pool, name, dedup_key, consumed_by, last_abandoned_by, last_stale_ack_by,'
        . ' payload, last_fail_reason, last_reschedule_reason';
}
