<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Model;

use Carbon\CarbonImmutable;

/** The named constructors are the only way to build a value, so `fail` / `reschedule` always carry an `availableAt`. */
final readonly class Settlement
{
    private function __construct(
        public Job $job,
        public Outcome $outcome,
        public ?Reason $reason = null,
        public ?CarbonImmutable $availableAt = null,
    ) {}

    public static function complete(Job $job): self
    {
        return new self($job, Outcome::Complete);
    }

    public static function discard(Job $job, Reason $why): self
    {
        return new self($job, Outcome::Discard, $why);
    }

    public static function fail(Job $job, Reason $why, CarbonImmutable $availableAt): self
    {
        return new self($job, Outcome::Fail, $why, $availableAt);
    }

    public static function reschedule(Job $job, Reason $why, CarbonImmutable $availableAt): self
    {
        return new self($job, Outcome::Reschedule, $why, $availableAt);
    }

    public static function release(Job $job): self
    {
        return new self($job, Outcome::Release);
    }
}
