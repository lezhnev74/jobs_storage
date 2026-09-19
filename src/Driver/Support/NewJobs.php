<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Support;

use Lezhnev74\Jobs\Contract\DescribesJob;
use Lezhnev74\Jobs\Model\NewJob;

/**
 * Normalizes a `push` variadic at the driver boundary so statement builders only ever see `NewJob`. Each
 * `DescribesJob` is converted exactly once, in argument order.
 */
final class NewJobs
{
    /** @return list<NewJob> */
    public static function of(NewJob|DescribesJob ...$jobs): array
    {
        return array_map(
            static fn(NewJob|DescribesJob $job): NewJob => $job instanceof NewJob ? $job : $job->toJob(),
            array_values($jobs),
        );
    }
}
