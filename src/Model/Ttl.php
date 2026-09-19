<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Model;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use InvalidArgumentException;

final readonly class Ttl
{
    private function __construct(public int $seconds)
    {
        if ($seconds <= 0) {
            throw new InvalidArgumentException(\sprintf('Ttl must be positive, got %d seconds', $seconds));
        }
    }

    public static function seconds(int $seconds): self
    {
        return new self($seconds);
    }

    public static function minutes(int $minutes): self
    {
        return new self($minutes * 60);
    }

    public static function hours(int $hours): self
    {
        return new self($hours * 3600);
    }

    public static function fromInterval(CarbonInterval $interval): self
    {
        return new self((int) $interval->totalSeconds);
    }

    public function expiresAfter(CarbonImmutable $from): CarbonImmutable
    {
        return $from->addSeconds($this->seconds);
    }

    public function toInterval(): CarbonInterval
    {
        return CarbonInterval::seconds($this->seconds);
    }
}
