<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Model;

use Lezhnev74\Jobs\Model\Outcome;
use Lezhnev74\Jobs\Query\State;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Outcome::class)]
#[CoversClass(State::class)]
final class EnumsTest extends TestCase
{
    public function testOutcomeClassification(): void
    {
        self::assertSame([Outcome::Complete, Outcome::Discard], array_values(array_filter(Outcome::cases(), static fn(Outcome $o): bool => $o->isTerminal())));
        self::assertSame([Outcome::Fail, Outcome::Reschedule], array_values(array_filter(Outcome::cases(), static fn(Outcome $o): bool => $o->requeues())));
        self::assertSame('release', Outcome::Release->value);
    }

    public function testStateTerminals(): void
    {
        self::assertSame([State::Discarded, State::Completed], array_values(array_filter(State::cases(), static fn(State $s): bool => $s->isTerminal())));
        self::assertSame('claimable', State::Claimable->value);
    }
}
