<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Model;

use Carbon\CarbonImmutable;

/** The fencing token is not here: it belongs to the claim event (`ClaimedJobs::$token`), never to a single job. */
final readonly class Lease
{
    public function __construct(
        public string $by,
        public CarbonImmutable $till,
    ) {}

    /** Expiry alone invalidates nothing; it only ends the state. */
    public function isLive(?CarbonImmutable $now = null): bool
    {
        return $this->till->greaterThan($now ?? CarbonImmutable::now());
    }
}
