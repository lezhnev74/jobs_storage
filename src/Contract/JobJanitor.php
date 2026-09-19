<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Contract;

use Carbon\CarbonImmutable;
use Lezhnev74\Jobs\Model\Reason;
use Lezhnev74\Jobs\Query\JobQuery;

/**
 * Destruction and rescue, kept out of `JobReader` so a dashboard cannot delete or requeue production jobs by
 * accident. Every verb honors `JobQuery::limit()` (`$q->orderBy` is ignored) and returns the rows it actually
 * touched, so callers loop over batches. Rows locked by a concurrent claim are skipped rather than waited on - an
 * ops sweep never blocks a claim, nor a claim a sweep. Lease liveness is judged on the database clock.
 */
interface JobJanitor
{
    /** Skips rows under a live lease (`consumed_till > now()`) so a worker mid-flight never loses its job. */
    public function delete(JobQuery $q): int;

    /**
     * Deletes live-leased rows too; the holder's eventual ack becomes a stale ack and its telemetry write matches
     * nothing. A separate method rather than a flag so the destructive variant is visible at the call site.
     */
    public function deleteIncludingLeased(JobQuery $q): int;

    /**
     * Re-arms matching jobs, terminated ones included: sets `available_at = $at`, writes `$why` to
     * `last_reschedule_reason`, clears the lease and both terminal markers. Counters are left as they are. A worker
     * still holding a lease on a requeued job is fenced out on its next ack.
     */
    public function requeue(JobQuery $q, CarbonImmutable $at, Reason $why): int;
}
