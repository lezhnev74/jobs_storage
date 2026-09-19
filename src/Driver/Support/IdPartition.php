<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Support;

use Lezhnev74\Jobs\Model\JobId;

/**
 * Both reports the drivers return are the same shape: the ids a statement touched, against the ids it was asked to
 * touch. One definition of that split, so `AckReport` and `PushReport` can never drift on ordering or duplicates.
 */
final class IdPartition
{
    /**
     * Requested ids keep their order and are de-duplicated by value; each lands in exactly one of the two lists.
     * `$returned` holds raw uuid strings, compared case-insensitively.
     *
     * @param  list<JobId> $requested
     * @param  list<string> $returned
     * @return array{list<JobId>, list<JobId>} the requested ids that were returned, and those that were not
     */
    public static function split(array $requested, array $returned): array
    {
        $returnedSet = array_fill_keys(array_map(strtolower(...), $returned), true);
        $seen = [];
        $matched = [];
        $missing = [];

        foreach ($requested as $id) {
            if (isset($seen[$id->value])) {
                continue;
            }
            $seen[$id->value] = true;

            if (isset($returnedSet[$id->value])) {
                $matched[] = $id;
            } else {
                $missing[] = $id;
            }
        }

        return [$matched, $missing];
    }
}
