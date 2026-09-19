<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Support;

use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Model\PushReport;

final class PushReports
{
    /**
     * An untargeted `DO NOTHING` cannot name which unique constraint fired, so the report is derived by diffing what
     * was asked for against what landed: an id absent from the insert is a duplicate id or a dedup-key collision,
     * and both are reported the same way.
     *
     * @param list<JobId> $requested
     * @param list<string> $inserted ids the insert actually stored
     */
    public static function diff(array $requested, array $inserted): PushReport
    {
        [$stored, $dropped] = IdPartition::split($requested, $inserted);

        return new PushReport($stored, $dropped);
    }
}
