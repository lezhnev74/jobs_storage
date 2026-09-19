<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Support;

use Carbon\CarbonImmutable;
use Lezhnev74\Jobs\Model\DedupKey;
use Lezhnev74\Jobs\Model\Job;
use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Model\JobName;
use Lezhnev74\Jobs\Model\Lease;
use Lezhnev74\Jobs\Model\PoolName;
use Lezhnev74\Jobs\Model\RetryState;
use Lezhnev74\Jobs\Model\Telemetry;
use Lezhnev74\Jobs\Model\Termination;

final class Fixtures
{
    public static function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, 'UTC');
    }

    public static function job(
        ?CarbonImmutable $availableAt = null,
        ?Termination $termination = null,
        ?Lease $lease = null,
        ?RetryState $retry = null,
        ?Telemetry $telemetry = null,
        ?JobId $id = null,
    ): Job {
        $created = self::at('2026-09-11 10:00:00');

        return new Job(
            id: $id ?? JobId::new(),
            name: new JobName('billing.invoice.send'),
            pool: new PoolName('emails'),
            payload: ['customer' => ['region' => 'eu']],
            dedupKey: DedupKey::fromString('fixture'),
            availableAt: $availableAt ?? $created,
            termination: $termination,
            lease: $lease,
            retry: $retry ?? RetryState::fresh(),
            telemetry: $telemetry ?? Telemetry::none(),
            createdAt: $created,
            updatedAt: $created,
        );
    }
}
