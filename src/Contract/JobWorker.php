<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Contract;

use Lezhnev74\Jobs\Model\AckReport;
use Lezhnev74\Jobs\Model\ClaimedJobs;
use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Model\LeaseToken;
use Lezhnev74\Jobs\Model\Settlement;
use Lezhnev74\Jobs\Model\Ttl;
use Lezhnev74\Jobs\Model\WorkerId;
use Lezhnev74\Jobs\Query\ClaimQuery;

/**
 * Delivery is at-least-once via TTL leases. An expired lease is simply claimable again and is reclaimed inside
 * `claim()` - there is no reaper.
 *
 * Fencing is by token equality, not clock: `consumed_till` passing invalidates nothing, and the token stops matching
 * only once another claim happened or the job was terminated or deleted. Staleness never throws; it is reported per
 * id through `AckReport`.
 *
 * The worker supplies TTLs and `availableAt` but never "now", so a skewed worker clock cannot corrupt leases.
 */
interface JobWorker
{
    /**
     * Atomic batch claim on `available_at <= now() AND (no lease OR consumed_till <= now()) AND no terminal marker`,
     * narrowed by `$q` (`$q->limit` is the batch size). ONE store-generated lease token covers the whole batch.
     * Abandonment (`abandoned_count`, `last_abandoned_by/at`) is counted only when the previous lease expired with
     * its token still attached, which means the previous worker died silently; a clean `release` never counts.
     *
     * Ordering is approximate oldest-first by `available_at`; rows mid-claim elsewhere are skipped, never waited on,
     * and two concurrent claimers never receive the same job.
     *
     * The token is minted even for an empty batch, so the worker loop needs no null branch.
     */
    public function claim(ClaimQuery $q, WorkerId $by, Ttl $ttl): ClaimedJobs;

    /**
     * Extends `consumed_till` to `now() + $extend`, guarded by the batch token. A job whose token no longer matches
     * is reported stale but not counted in `stale_ack_*` - the stale settlement that follows is counted instead.
     */
    public function heartbeat(LeaseToken $t, Ttl $extend, JobId ...$ids): AckReport;

    /**
     * Any outcome mix under one lease token. Each `Settlement` carries the originally claimed `Job`, of which only
     * the id is used. The fence is the single atomic predicate
     * `lease_token = $t AND completed_at IS NULL AND discarded_at IS NULL`, so a late ack after TTL still lands
     * unless the job was re-claimed or terminated meanwhile.
     *
     * `fail` requeues at `availableAt` and bumps `consecutive_failures` while resetting `consecutive_reschedules`;
     * `reschedule` is the mirror image; `release` clears the lease and touches nothing else. Terminal settlements
     * freeze the streak counters. Every outcome clears the lease token.
     *
     * A sibling reclaimed elsewhere carries a different token, so only that job's ack is rejected while the rest
     * land. Stale terminating outcomes are recorded (`stale_ack_count++`, `last_stale_ack_by/at`) on a telemetry-only
     * path that cannot race the legitimate owner; a stale `release` is reported but not counted. Ids that no longer
     * exist are reported stale as well.
     *
     * Join the report back to the claim with `ClaimedJobs::unsettled()` (never reported - carry into the next round)
     * and `ClaimedJobs::stale()` (drop: re-settling under a dead token never lands).
     */
    public function settle(LeaseToken $t, Settlement ...$batch): AckReport;
}
