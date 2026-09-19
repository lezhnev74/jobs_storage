<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Model;

/** Values match the `outcome` labels of the mixed-batch unnest statement. */
enum Outcome: string
{
    case Complete = 'complete';
    case Discard = 'discard';
    case Fail = 'fail';
    case Reschedule = 'reschedule';
    case Release = 'release';

    public function isTerminal(): bool
    {
        return $this === self::Complete || $this === self::Discard;
    }

    public function requeues(): bool
    {
        return $this === self::Fail || $this === self::Reschedule;
    }
}
