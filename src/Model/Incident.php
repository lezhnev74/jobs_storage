<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Model;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class Incident
{
    public function __construct(
        public int $count,
        public string $by,
        public CarbonImmutable $at,
    ) {
        if ($count < 1) {
            throw new InvalidArgumentException(\sprintf('Incident count must be positive, got %d', $count));
        }
    }
}
