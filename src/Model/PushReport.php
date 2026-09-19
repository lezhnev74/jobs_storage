<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Model;

/**
 * Dedup is reported, never thrown - a dropped job is a normal outcome, but a silent one would be the feature's only
 * dangerous failure mode. Shaped like `AckReport` on purpose.
 */
final readonly class PushReport
{
    /**
     * @param list<JobId> $inserted      stored as a new row
     * @param list<JobId> $deduplicated  dropped: the id already existed, or a non-terminal row in the pool holds the same dedup key
     */
    public function __construct(
        public array $inserted,
        public array $deduplicated,
    ) {}

    public function isClean(): bool
    {
        return $this->deduplicated === [];
    }

    public function wasInserted(JobId $id): bool
    {
        return $id->isIn(...$this->inserted);
    }

    public function wasDeduplicated(JobId $id): bool
    {
        return $id->isIn(...$this->deduplicated);
    }
}
