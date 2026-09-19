<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Query;

/** Never stored - derived from the row's fields. Values match the SQL `CASE` labels used by `aggregate()`. */
enum State: string
{
    case Discarded = 'discarded';
    case Completed = 'completed';
    case Claimed = 'claimed';
    case Scheduled = 'scheduled';
    case Claimable = 'claimable';

    public function isTerminal(): bool
    {
        return $this === self::Discarded || $this === self::Completed;
    }
}
