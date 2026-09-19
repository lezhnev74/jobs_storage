<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Support;

use Lezhnev74\Jobs\Contract\DescribesJob;
use Lezhnev74\Jobs\Model\NewJob;

/**
 * Minimal `DescribesJob` for tests: wraps a prepared `NewJob` and counts conversions, so a suite can assert both
 * the stored result and that `toJob()` ran exactly once per push.
 */
final class DescribedJob implements DescribesJob
{
    public int $conversions = 0;

    public function __construct(private readonly NewJob $job) {}

    public function toJob(): NewJob
    {
        ++$this->conversions;

        return $this->job;
    }
}
