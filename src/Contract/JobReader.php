<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Contract;

use Lezhnev74\Jobs\Model\DedupKey;
use Lezhnev74\Jobs\Model\Job;
use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Model\PoolName;
use Lezhnev74\Jobs\Query\GroupBy;
use Lezhnev74\Jobs\Query\JobQuery;

/**
 * Observation only - no claim, no destruction. State is never stored: `JobQuery::states()` compiles to inference
 * rules evaluated on the database clock and is the authoritative filter, while `Job::state()` on a returned snapshot
 * is display only.
 */
interface JobReader
{
    public function get(JobId $id): ?Job;

    /**
     * The non-terminal job holding `$key` in `$pool`, or null when the key is free. Answers the producer's question
     * after a deduplicated push: which row holds my key, and what is it doing? At most one row can match - that is the
     * dedup constraint - and the lookup compiles straight to the index enforcing it.
     *
     * Terminal rows are invisible here: a completed run no longer holds the key. Read the history of a key across all
     * states with `JobQuery::dedupKeys()` instead.
     */
    public function find(PoolName $pool, DedupKey $key): ?Job;

    /**
     * Default order is id ascending, which is creation order for UUIDv7 ids. Iterable rather than array so drivers
     * may stream large results; a streaming driver gives no snapshot consistency across the whole traversal.
     *
     * **Both bundled drivers materialize the whole result in memory**, so an unbounded `read()` is bounded only by
     * the size of the result set - a pool with a large history will exhaust the memory limit. Anything reading more
     * than a screenful must bound the query: `limit()` for one page, plus `JobQuery::after()` to walk the rest, which
     * pages on the primary key rather than re-scanning skipped rows. One page is a snapshot; a whole walk is not.
     *
     * @return iterable<Job>
     */
    public function read(JobQuery $q): iterable;

    /** `$q->orderBy`, `$q->limit` and `$q->after` are ignored. */
    public function count(JobQuery $q): int;

    /**
     * `$q->orderBy`, `$q->limit` and `$q->after` are ignored. Grouping happens on the column in SQL, never through
     * the model, so `consumed_by` reports the worker axis even for settled jobs. The group key is null for rows with
     * no value on that axis; group order is unspecified.
     *
     * @return list<array{group: ?string, count: int}>
     */
    public function aggregate(JobQuery $q, GroupBy $g): array;
}
