<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Model;

use Carbon\CarbonImmutable;
use Lezhnev74\Jobs\Query\State;

final readonly class Job
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public JobId $id,
        public JobName $name,
        public PoolName $pool,
        public array $payload,
        /** Exposed so an operator debugging "why was my job dropped" can see what the row was deduplicated on. */
        public DedupKey $dedupKey,
        public CarbonImmutable $availableAt,
        public ?Termination $termination,
        public ?Lease $lease,
        public RetryState $retry,
        public Telemetry $telemetry,
        public CarbonImmutable $createdAt,
        public CarbonImmutable $updatedAt,
    ) {}

    /**
     * Derived on demand, never stored - display and worker-local. Anything that must be *correct* about state
     * filters via `JobQuery::states()` in the DB instead.
     */
    public function state(?CarbonImmutable $now = null): State
    {
        if ($this->termination !== null) {
            return self::terminalState($this->termination);
        }

        $now ??= CarbonImmutable::now();

        if ($this->lease?->isLive($now) === true) {
            return State::Claimed;
        }

        return $this->availableAt->greaterThan($now) ? State::Scheduled : State::Claimable;
    }

    private static function terminalState(Termination $termination): State
    {
        return match ($termination->kind) {
            TerminalKind::Discarded => State::Discarded,
            TerminalKind::Completed => State::Completed,
        };
    }

    /**
     * Pure filters over any job list - claim results, query results, in-memory sets. Input order is preserved and
     * ids absent from the list are ignored, so nothing here can throw.
     *
     * @param  list<Job> $jobs
     * @return list<Job>
     */
    public static function only(array $jobs, JobId ...$ids): array
    {
        return array_values(array_filter($jobs, static fn(self $job): bool => $job->id->isIn(...$ids)));
    }

    /**
     * @param  list<Job> $jobs
     * @return list<Job>
     */
    public static function except(array $jobs, JobId ...$ids): array
    {
        return array_values(array_filter($jobs, static fn(self $job): bool => !$job->id->isIn(...$ids)));
    }

    /**
     * Jobs no report mentions, acked or stale - never attempted rather than failed. Strictly larger than the stale
     * set: it also covers a worker that crashed mid-batch or returned early.
     *
     * @param  list<Job> $jobs
     * @return list<Job>
     */
    public static function unreported(array $jobs, AckReport ...$reports): array
    {
        $merged = AckReport::merge(...$reports);

        return self::except($jobs, ...$merged->acked, ...$merged->stale);
    }

    /**
     * Jobs fenced out of the reports - another worker owns them, or they were terminated or deleted. Drop them;
     * re-settling under the same token silently no-ops forever.
     *
     * @param  list<Job> $jobs
     * @return list<Job>
     */
    public static function stale(array $jobs, AckReport ...$reports): array
    {
        return self::only($jobs, ...AckReport::merge(...$reports)->stale);
    }
}
