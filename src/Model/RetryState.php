<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Model;

use InvalidArgumentException;

final readonly class RetryState
{
    /**
     * @param array<mixed>|null $lastFailReason
     * @param array<mixed>|null $lastRescheduleReason
     */
    public function __construct(
        public int $attempts = 0,
        public int $consecutiveFailures = 0,
        public int $consecutiveReschedules = 0,
        public ?array $lastFailReason = null,
        public ?array $lastRescheduleReason = null,
    ) {
        if (min($attempts, $consecutiveFailures, $consecutiveReschedules) < 0) {
            throw new InvalidArgumentException('RetryState counters must not be negative');
        }
    }

    public static function fresh(): self
    {
        return new self();
    }
}
