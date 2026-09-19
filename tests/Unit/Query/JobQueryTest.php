<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Query;

use InvalidArgumentException;
use Lezhnev74\Jobs\Model\DedupKey;
use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Model\PoolName;
use Lezhnev74\Jobs\Query\ClaimQuery;
use Lezhnev74\Jobs\Query\JobQuery;
use Lezhnev74\Jobs\Query\Order;
use Lezhnev74\Jobs\Query\State;
use Lezhnev74\Jobs\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(JobQuery::class)]
final class JobQueryTest extends TestCase
{
    public function testFromCarriesClaimCriteriaAndAppliesDefaults(): void
    {
        $claim = ClaimQuery::pool(new PoolName('emails'))
            ->namePrefix('billing.')
            ->payload('$.customer.region', 'eu')
            ->limit(50);

        $q = JobQuery::from($claim);

        self::assertSame($claim, $q->claim);
        self::assertSame('emails', $q->claim->pool->value);
        self::assertSame('billing.', $q->claim->namePrefix);
        self::assertSame(['$.customer.region' => 'eu'], $q->claim->payload);
        self::assertSame([], $q->states);
        self::assertSame([], $q->dedupKeys);
        self::assertNull($q->availableBefore);
        self::assertNull($q->availableAfter);
        self::assertSame(Order::IdAsc, $q->orderBy);
        self::assertNull($q->limit);
        self::assertNull($q->after);
    }

    public function testWithersReturnNewInstancesAndLeaveTheOriginalUntouched(): void
    {
        $before = Fixtures::at('2026-09-11 12:00:00');
        $after = Fixtures::at('2026-09-11 11:00:00');
        $base = JobQuery::from(ClaimQuery::pool(new PoolName('emails')));

        $derived = $base->states(State::Claimable)
            ->availableBefore($before)
            ->availableAfter($after)
            ->orderBy(Order::AvailableAtAsc)
            ->limit(100);

        self::assertNotSame($base, $derived);
        self::assertSame([], $base->states);
        self::assertNull($base->availableBefore);
        self::assertNull($base->availableAfter);
        self::assertSame(Order::IdAsc, $base->orderBy);
        self::assertNull($base->limit);
        self::assertNull($base->after);

        self::assertSame($base->claim, $derived->claim);
        self::assertSame([State::Claimable], $derived->states);
        self::assertSame($before, $derived->availableBefore);
        self::assertSame($after, $derived->availableAfter);
        self::assertSame(Order::AvailableAtAsc, $derived->orderBy);
        self::assertSame(100, $derived->limit);
    }

    public function testDedupKeysAccumulateWithoutDuplicatesAndLeaveTheOriginalUntouched(): void
    {
        $base = JobQuery::from(ClaimQuery::pool(new PoolName('p')));

        $derived = $base->dedupKeys(DedupKey::fromString('a'), DedupKey::fromString('b'))
            ->dedupKeys(DedupKey::fromString('a'))
            ->dedupKeys(DedupKey::fromString('c'));

        self::assertSame([], $base->dedupKeys);
        self::assertSame(['a', 'b', 'c'], array_map(static fn(DedupKey $k): string => $k->value, $derived->dedupKeys));
    }

    public function testStatesAccumulateWithoutDuplicates(): void
    {
        $q = JobQuery::from(ClaimQuery::pool(new PoolName('p')))
            ->states(State::Claimable, State::Scheduled)
            ->states(State::Claimed)
            ->states(State::Claimable);

        self::assertSame([State::Claimable, State::Scheduled, State::Claimed], $q->states);
    }

    public function testTimeBoundsAndOrderReplaceEarlierValues(): void
    {
        $t1 = Fixtures::at('2026-09-11 12:00:00');
        $t2 = Fixtures::at('2026-09-11 13:00:00');

        $q = JobQuery::from(ClaimQuery::pool(new PoolName('p')))
            ->availableBefore($t1)->availableBefore($t2)
            ->availableAfter($t1)->availableAfter(Fixtures::at('2026-09-11 10:00:00'))
            ->orderBy(Order::AvailableAtDesc)->orderBy(Order::IdDesc);

        self::assertSame($t2, $q->availableBefore);
        self::assertTrue($q->availableAfter?->eq(Fixtures::at('2026-09-11 10:00:00')));
        self::assertSame(Order::IdDesc, $q->orderBy);
    }

    public function testRejectsEmptyWindow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JobQuery::from(ClaimQuery::pool(new PoolName('p')))
            ->availableAfter(Fixtures::at('2026-09-11 12:00:00'))
            ->availableBefore(Fixtures::at('2026-09-11 12:00:00'));
    }

    public function testRejectsNonPositiveLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JobQuery::from(ClaimQuery::pool(new PoolName('p')))->limit(0);
    }

    public function testAfterReplacesEarlierCursorsAndSurvivesOtherWithers(): void
    {
        $first = JobId::new();
        $second = JobId::new();
        $base = JobQuery::from(ClaimQuery::pool(new PoolName('p')));

        $derived = $base->after($first)->after($second)->limit(10)->states(State::Claimable)->orderBy(Order::IdDesc);

        self::assertNull($base->after);
        self::assertSame($second, $derived->after);
    }

    public function testAfterAcceptsBothIdOrders(): void
    {
        $cursor = JobId::new();
        $base = JobQuery::from(ClaimQuery::pool(new PoolName('p')));

        self::assertSame($cursor, $base->orderBy(Order::IdAsc)->after($cursor)->after);
        self::assertSame($cursor, $base->orderBy(Order::IdDesc)->after($cursor)->after);
    }

    #[TestWith([Order::AvailableAtAsc])]
    #[TestWith([Order::AvailableAtDesc])]
    public function testRejectsACursorUnderANonIdOrder(Order $order): void
    {
        $this->expectException(InvalidArgumentException::class);

        JobQuery::from(ClaimQuery::pool(new PoolName('p')))->orderBy($order)->after(JobId::new());
    }

    #[TestWith([Order::AvailableAtAsc])]
    #[TestWith([Order::AvailableAtDesc])]
    public function testRejectsReorderingAnExistingCursorOntoANonIdOrder(Order $order): void
    {
        $q = JobQuery::from(ClaimQuery::pool(new PoolName('p')))->after(JobId::new());

        $this->expectException(InvalidArgumentException::class);

        $q->orderBy($order);
    }
}
