<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Support;

use Lezhnev74\Jobs\Model\AckReport;
use Lezhnev74\Jobs\Model\JobId;

final class AckReports
{
    /**
     * Ids the settle/heartbeat statement did not return were fenced out by the token.
     *
     * @param list<JobId> $requested
     * @param list<string> $returned
     */
    public static function diff(array $requested, array $returned): AckReport
    {
        [$acked, $stale] = IdPartition::split($requested, $returned);

        return new AckReport($acked, $stale);
    }
}
