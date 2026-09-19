<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Support;

use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Model\NewJob;

/**
 * Which jobs of a push batch are worth sending to the insert. MySQL needs this because any InnoDB insert that hits a
 * duplicate takes a lock on the duplicate record and waits for whoever holds it, so a push colliding with a claimed
 * row would block on that worker's transaction. Filtering the known collisions out beforehand keeps the insert away
 * from those records entirely.
 *
 * Pure: the probe's findings go in, survivors and dropped ids come out; the driver owns the SQL.
 */
final readonly class PushPlan
{
    /**
     * @param list<NewJob> $survivors  jobs with no known holder, in batch order
     * @param list<JobId> $dropped     ids a holder already covers (duplicate id, held key, or an earlier copy of the
     *                                 same key within this batch)
     */
    private function __construct(
        public array $survivors,
        public array $dropped,
    ) {}

    /**
     * First copy of a key within the batch wins, matching what the unique index would have done and keeping the
     * outcome independent of the probe's timing.
     *
     * @param  list<NewJob> $jobs
     * @param  list<string> $existingIds  ids already present, raw uuid strings
     * @param  list<array{string, string}> $heldKeys  `[pool, dedup key]` pairs a non-terminal row already holds
     */
    public static function of(array $jobs, array $existingIds, array $heldKeys): self
    {
        $taken = array_fill_keys(array_map(strtolower(...), $existingIds), true);
        foreach ($heldKeys as [$pool, $key]) {
            $taken[self::slot($pool, $key)] = true;
        }

        $survivors = [];
        $dropped = [];
        foreach ($jobs as $job) {
            $slot = self::slot($job->pool->value, $job->dedupKey->value);
            if (isset($taken[$job->id->value]) || isset($taken[$slot])) {
                $dropped[] = $job->id;

                continue;
            }
            $taken[$job->id->value] = true;
            $taken[$slot] = true;
            $survivors[] = $job;
        }

        return new self($survivors, $dropped);
    }

    /** @return list<JobId> */
    public function survivorIds(): array
    {
        return array_map(static fn(NewJob $job): JobId => $job->id, $this->survivors);
    }

    /** Ids and slots share one map; the separator cannot occur in a uuid, so the two namespaces never overlap. */
    private static function slot(string $pool, string $key): string
    {
        return $pool . "\0" . $key;
    }
}
