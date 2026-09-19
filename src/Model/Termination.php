<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Model;

use Carbon\CarbonImmutable;

final readonly class Termination
{
    public function __construct(
        public TerminalKind $kind,
        public CarbonImmutable $at,
    ) {}

    public static function completed(CarbonImmutable $at): self
    {
        return new self(TerminalKind::Completed, $at);
    }

    public static function discarded(CarbonImmutable $at): self
    {
        return new self(TerminalKind::Discarded, $at);
    }
}
