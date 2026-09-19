<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Model;

/**
 * `consumed_by` outlives the lease in the row - settlement clears `consumed_till` only - so `$lastClaimedBy`
 * survives once the job is no longer leased.
 */
final readonly class Telemetry
{
    public function __construct(
        public ?Incident $abandonment = null,
        public ?Incident $staleAck = null,
        public ?string $lastClaimedBy = null,
    ) {}

    public static function none(): self
    {
        return new self();
    }
}
