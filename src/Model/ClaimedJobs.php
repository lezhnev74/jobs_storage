<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Model;

use Carbon\CarbonImmutable;
use Countable;

/** The token is minted even for an empty claim, so the worker's loop needs no null branch. */
final readonly class ClaimedJobs implements Countable
{
    /** @param list<Job> $jobs */
    public function __construct(
        public LeaseToken $token,
        public CarbonImmutable $leasedUntil,
        public array $jobs,
    ) {}

    public function count(): int
    {
        return \count($this->jobs);
    }

    public function isEmpty(): bool
    {
        return $this->jobs === [];
    }

    /** @return list<JobId> */
    public function ids(): array
    {
        return array_map(static fn(Job $job): JobId => $job->id, $this->jobs);
    }

    /** @return list<Job> */
    public function only(JobId ...$ids): array
    {
        return Job::only($this->jobs, ...$ids);
    }

    /** @return list<Job> */
    public function except(JobId ...$ids): array
    {
        return Job::except($this->jobs, ...$ids);
    }

    /**
     * Claimed jobs absent from every report - the recovery set to carry into the next round. "Not reported", which
     * includes never attempted; not "failed".
     *
     * @return list<Job>
     */
    public function unsettled(AckReport ...$reports): array
    {
        return Job::unreported($this->jobs, ...$reports);
    }

    /**
     * Claimed jobs the token no longer owns. Drop them - settling them again under this token never lands.
     *
     * @return list<Job>
     */
    public function stale(AckReport ...$reports): array
    {
        return Job::stale($this->jobs, ...$reports);
    }
}
