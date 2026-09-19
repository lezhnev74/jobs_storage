<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Postgres;

use Carbon\CarbonImmutable;
use Lezhnev74\Jobs\Contract\DescribesJob;
use Lezhnev74\Jobs\Contract\JobQueue;
use Lezhnev74\Jobs\Driver\Support\AckReports;
use Lezhnev74\Jobs\Driver\Support\NewJobs;
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
use PDOStatement;

/**
 * One autocommit statement per call, plus the stale-ack telemetry write on the settle rejection path. The SQL lives
 * in `QueueStatements`.
 */
final class PostgresQueue implements JobQueue
{
    public function __construct(private readonly PDO $pdo) {}

    public function push(NewJob|DescribesJob ...$jobs): PushReport
    {
        $jobs = NewJobs::of(...$jobs);
        if ($jobs === []) {
            return new PushReport([], []);
        }
        $requested = array_map(static fn(NewJob $job): JobId => $job->id, $jobs);

        return PushReports::diff($requested, $this->fetchIds(QueueStatements::push(...$jobs)));
    }

    public function claim(ClaimQuery $q, WorkerId $by, Ttl $ttl): ClaimedJobs
    {
        $token = LeaseToken::generate($by);
        $jobs = $this->fetchJobs(QueueStatements::claim($q, $token, $by, $ttl));

        return new ClaimedJobs($token, self::leasedUntil($jobs, $ttl), $jobs);
    }

    public function heartbeat(LeaseToken $t, Ttl $extend, JobId ...$ids): AckReport
    {
        $ids = array_values($ids);
        if ($ids === []) {
            return new AckReport([], []);
        }

        return AckReports::diff($ids, $this->fetchIds(QueueStatements::heartbeat($t, $extend, $ids)));
    }

    public function settle(LeaseToken $t, Settlement ...$batch): AckReport
    {
        $batch = SettleBatch::of(...$batch);
        if ($batch->isEmpty()) {
            return new AckReport([], []);
        }
        $report = AckReports::diff($batch->ids(), $this->fetchIds(QueueStatements::settle($batch, $t)));
        $this->recordStaleAcks($batch->staleToCount($report), $t->worker);

        return $report;
    }

    /** @param list<JobId> $ids */
    private function recordStaleAcks(array $ids, WorkerId $by): void
    {
        if ($ids === []) {
            return;
        }
        $this->run(QueueStatements::staleAck($ids, $by));
    }

    /**
     * Every claimed row carries the same `consumed_till`, since `now()` is evaluated once per statement. An empty
     * claim has no row to read it from; its expiry is then the PHP clock plus TTL, informational only - nothing
     * compares against it.
     *
     * @param list<Job> $jobs
     */
    private static function leasedUntil(array $jobs, Ttl $ttl): CarbonImmutable
    {
        $lease = $jobs[0]->lease ?? null;

        return $lease === null ? $ttl->expiresAfter(CarbonImmutable::now()) : $lease->till;
    }

    /** @return list<Job> */
    private function fetchJobs(Sql $sql): array
    {
        $rows = array_filter($this->run($sql)->fetchAll(PDO::FETCH_ASSOC), \is_array(...));

        return array_values(array_map(JobHydrator::hydrate(...), $rows));
    }

    /** @return list<string> */
    private function fetchIds(Sql $sql): array
    {
        return array_values(array_filter($this->run($sql)->fetchAll(PDO::FETCH_COLUMN), \is_string(...)));
    }

    private function run(Sql $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql->text);
        $statement->execute($sql->params);

        return $statement;
    }
}
