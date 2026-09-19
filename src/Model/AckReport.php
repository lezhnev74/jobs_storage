<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Model;

/** Partial staleness is reported, never thrown. */
final readonly class AckReport
{
    /**
     * @param list<JobId> $acked acknowledged (settled, or lease extended)
     * @param list<JobId> $stale fenced out (token mismatch); `stale_ack` recorded on terminating outcomes only
     */
    public function __construct(
        public array $acked,
        public array $stale,
    ) {}

    public function isClean(): bool
    {
        return $this->stale === [];
    }

    public function isAcked(JobId $id): bool
    {
        return $id->isIn(...$this->acked);
    }

    public function isStale(JobId $id): bool
    {
        return $id->isIn(...$this->stale);
    }

    /**
     * Collapses the rounds a worker settled under one token. An id both acked and stale across rounds is stale in
     * the merge: the pessimistic reading is the safe one for deciding whether the worker still owns the job.
     */
    public static function merge(self ...$reports): self
    {
        $acked = [];
        $stale = [];

        foreach ($reports as $report) {
            foreach ($report->acked as $id) {
                if (!$id->isIn(...$acked)) {
                    $acked[] = $id;
                }
            }

            foreach ($report->stale as $id) {
                if (!$id->isIn(...$stale)) {
                    $stale[] = $id;
                }
            }
        }

        $acked = array_values(array_filter($acked, static fn(JobId $id): bool => !$id->isIn(...$stale)));

        return new self($acked, $stale);
    }
}
