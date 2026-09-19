<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Model;

use Lezhnev74\Jobs\Model\Outcome;
use Lezhnev74\Jobs\Model\Reason;
use Lezhnev74\Jobs\Model\Settlement;
use Lezhnev74\Jobs\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(Settlement::class)]
final class SettlementTest extends TestCase
{
    /** @return iterable<string, array{callable(): Settlement, Outcome, bool, bool}> */
    public static function constructors(): iterable
    {
        $job = Fixtures::job();
        $why = new Reason(['code' => 'timeout']);
        $at = Fixtures::at('2026-09-11 13:00:00');

        yield 'complete' => [static fn(): Settlement => Settlement::complete($job), Outcome::Complete, false, false];
        yield 'discard' => [static fn(): Settlement => Settlement::discard($job, $why), Outcome::Discard, true, false];
        yield 'fail' => [static fn(): Settlement => Settlement::fail($job, $why, $at), Outcome::Fail, true, true];
        yield 'reschedule' => [static fn(): Settlement => Settlement::reschedule($job, $why, $at), Outcome::Reschedule, true, true];
        yield 'release' => [static fn(): Settlement => Settlement::release($job), Outcome::Release, false, false];
    }

    /** @param callable(): Settlement $build */
    #[DataProvider('constructors')]
    public function testConstructorShape(callable $build, Outcome $outcome, bool $hasReason, bool $hasAvailableAt): void
    {
        $settlement = $build();

        self::assertSame($outcome, $settlement->outcome);
        self::assertSame($hasReason, $settlement->reason !== null);
        self::assertSame($hasAvailableAt, $settlement->availableAt !== null);
        self::assertSame($hasAvailableAt, $outcome->requeues());
    }

    public function testCarriesTheOriginalJobAndInputs(): void
    {
        $job = Fixtures::job();
        $why = new Reason(['code' => 'rate-limit']);
        $at = Fixtures::at('2026-09-11 13:00:00');

        $settlement = Settlement::reschedule($job, $why, $at);

        self::assertSame($job, $settlement->job);
        self::assertSame($why, $settlement->reason);
        self::assertSame($at, $settlement->availableAt);
    }

    public function testOnlyNamedConstructorsExist(): void
    {
        self::assertFalse((new ReflectionClass(Settlement::class))->getConstructor()?->isPublic());
    }
}
