<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Mysql;

use Carbon\CarbonImmutable;
use Lezhnev74\Jobs\Contract\DescribesJob;
use Lezhnev74\Jobs\Contract\JobQueue;
use Lezhnev74\Jobs\Driver\Support\AckReports;
use Lezhnev74\Jobs\Driver\Support\NewJobs;
use Lezhnev74\Jobs\Driver\Support\PushPlan;
use Lezhnev74\Jobs\Driver\Support\PushReports;
use Lezhnev74\Jobs\Driver\Support\SettleBatch;
use Lezhnev74\Jobs\Model\AckReport;
use Lezhnev74\Jobs\Model\ClaimedJobs;
use Lezhnev74\Jobs\Model\Job;
use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Model\LeaseToken;
use Lezhnev74\Jobs\Model\NewJob;
use Lezhnev74\Jobs\Model\PushReport;
use Lezhnev74\Jobs\Model\Settlement;
use Lezhnev74\Jobs\Model\Ttl;
use Lezhnev74\Jobs\Model\WorkerId;
use Lezhnev74\Jobs\Query\ClaimQuery;
use PDO;

/**
 * `heartbeat` and the stale-ack telemetry are single autocommit statements; `push`, `claim` and `settle` are one
 * short transaction each, forced by the absence of `RETURNING`.
 */
final class MysqlQueue implements JobQueue
{
    private readonly Statements $statements;

    public function __construct(PDO $pdo)
    {
        $this->statements = new Statements($pdo);
    }

    /**
     * The only call that needs a transaction purely to *report*: without `RETURNING`, the ids that landed are the
     * ones absent before the insert and present after it, and both probes must see one consistent table.
     *
     * The first probe is also what keeps push from blocking. An InnoDB insert that hits a duplicate locks the
     * duplicate record and waits for its holder, so a plain insert of the whole batch would wait on any colliding row
     * a `claim` or `settle` currently holds; Postgres' `DO NOTHING` never waits. Sending only the survivors keeps the
     * insert away from those records. A collision appearing inside the probe-to-insert window still waits, bounded by
     * that one short transaction - and the after-probe keeps the report honest either way.
     */
    public function push(NewJob|DescribesJob ...$jobs): PushReport
    {
        $jobs = NewJobs::of(...$jobs);
        if ($jobs === []) {
            return new PushReport([], []);
        }
        $requested = array_map(static fn(NewJob $job): JobId => $job->id, $jobs);

        return $this->statements->transactional(function () use ($jobs, $requested): PushReport {
            $plan = $this->plan($jobs);
            if ($plan->survivors === []) {
                return PushReports::diff($requested, []);
            }
            $survivorIds = $plan->survivorIds();
            $this->statements->run(QueueStatements::push(...$plan->survivors));

            return PushReports::diff($requested, $this->statements->ids(QueueStatements::existingIds($survivorIds)));
        });
    }

    /**
     * Rows stay locked from pick to re-select, so the UPDATE matches exactly what was picked. The lease expiry is
     * read back rather than computed in PHP because `NOW(6)` is evaluated per statement.
     */
    public function claim(ClaimQuery $q, WorkerId $by, Ttl $ttl): ClaimedJobs
    {
        $token = LeaseToken::generate($by);
        $jobs = $this->statements->lockThenAct(
            QueueStatements::pick($q),
            function (array $ids) use ($token, $by, $ttl): array {
                $this->statements->run(QueueStatements::lease($ids, $token, $by, $ttl));

                return array_map(JobHydrator::hydrate(...), $this->statements->rows(QueueStatements::hydrate($ids)));
            },
            [],
        );

        return new ClaimedJobs($token, self::leasedUntil($jobs, $ttl), $jobs);
    }

    /**
     * The UPDATE clears nothing, so the ids still carrying the token afterwards are the acked set. `rowCount()` is
     * only a shortcut past that re-select: it is exact when the connection sets `PDO::MYSQL_ATTR_FOUND_ROWS`, and
     * otherwise merely undercounts rows whose `consumed_till` did not change - a wasted re-select, never a wrong
     * answer.
     */
    public function heartbeat(LeaseToken $t, Ttl $extend, JobId ...$ids): AckReport
    {
        $ids = array_values($ids);
        if ($ids === []) {
            return new AckReport([], []);
        }
        $extended = $this->statements->run(QueueStatements::heartbeat($t, $extend, $ids))->rowCount();
        if ($extended === \count($ids)) {
            return new AckReport($ids, []);
        }

        return AckReports::diff($ids, $this->statements->ids(QueueStatements::ackedIds($ids, $t, lock: false)));
    }

    /**
     * Settlement clears the token, so the acked set must be established before the write: the `FOR UPDATE`
     * lock-select is the `AckReport`, and the UPDATE then applies to exactly those rows.
     */
    public function settle(LeaseToken $t, Settlement ...$batch): AckReport
    {
        $batch = SettleBatch::of(...$batch);
        if ($batch->isEmpty()) {
            return new AckReport([], []);
        }
        $requested = $batch->ids();
        $report = $this->statements->lockThenAct(
            QueueStatements::ackedIds($requested, $t, lock: true),
            function (array $acked) use ($batch, $requested, $t): AckReport {
                $this->statements->run(QueueStatements::settle($batch, $acked, $t));

                return AckReports::diff($requested, MysqlLiteral::idParams($acked));
            },
            AckReports::diff($requested, []),
        );
        $this->recordStaleAcks($batch->staleToCount($report), $t->worker);

        return $report;
    }

    /**
     * Partitions the batch against one non-locking probe. Rows whose slot is NULL (terminal) match the id half only,
     * and a terminal row does not hold its key - so its `dedup_slot` is skipped rather than read as held.
     *
     * @param list<NewJob> $jobs
     */
    private function plan(array $jobs): PushPlan
    {
        $ids = [];
        $keys = [];
        foreach ($this->statements->rows(QueueStatements::collisions($jobs)) as $row) {
            if (\is_string($row['id'])) {
                $ids[] = $row['id'];
            }
            if (\is_string($row['pool']) && \is_string($row['dedup_slot'])) {
                $keys[] = [$row['pool'], $row['dedup_slot']];
            }
        }

        return PushPlan::of($jobs, $ids, $keys);
    }

    /** @param list<JobId> $ids */
    private function recordStaleAcks(array $ids, WorkerId $by): void
    {
        if ($ids === []) {
            return;
        }
        $this->statements->run(QueueStatements::staleAck($ids, $by));
    }

    /**
     * Every claimed row carries the same `consumed_till`, since `NOW(6)` is evaluated once per statement. An empty
     * claim has no row to read it from, so its expiry falls back to the PHP clock - informational only, nothing
     * compares against it.
     *
     * @param list<Job> $jobs
     */
    private static function leasedUntil(array $jobs, Ttl $ttl): CarbonImmutable
    {
        $lease = $jobs[0]->lease ?? null;

        return $lease === null ? $ttl->expiresAfter(CarbonImmutable::now()) : $lease->till;
    }
}
