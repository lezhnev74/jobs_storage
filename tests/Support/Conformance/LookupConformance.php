<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Support\Conformance;

use Carbon\CarbonImmutable;
use Lezhnev74\Jobs\Model\DedupKey;
use Lezhnev74\Jobs\Model\Job;
use Lezhnev74\Jobs\Model\PoolName;
use Lezhnev74\Jobs\Model\Reason;
use Lezhnev74\Jobs\Model\Settlement;
use Lezhnev74\Jobs\Query\Order;
use Lezhnev74\Jobs\Query\State;
use Lezhnev74\Jobs\Tests\Support\DriverConformanceTestCase;

/**
 * Reading the second identity back. `find()` answers the producer's question after a deduplicated push - which row
 * holds my key, and what is it doing - and so sees non-terminal rows only, exactly the scope of the constraint.
 * `JobQuery::dedupKeys()` is the operator's view: every run of a key, terminal ones included.
 *
 * @phpstan-require-extends DriverConformanceTestCase
 */
trait LookupConformance
{
    public function testFindReturnsTheRowHoldingTheKey(): void
    {
        $new = $this->newJob(dedupKey: 'invoice:2026-09:acme', payload: ['customer' => 7]);
        $this->queue()->push($new);

        $found = $this->find('invoice:2026-09:acme');

        self::assertNotNull($found);
        self::assertSame($new->id->value, $found->id->value);
        self::assertSame('invoice:2026-09:acme', $found->dedupKey->value);
        self::assertSameDocument(['customer' => 7], $found->payload);
    }

    public function testFindReturnsNullWhenNoRowHoldsTheKey(): void
    {
        $this->queue()->push($this->newJob(dedupKey: 'other'));

        self::assertNull($this->find('never-pushed'));
    }

    /** The lookup that closes the loop on a dropped push: the report says deduplicated, `find` says by what. */
    public function testFindIdentifiesTheHolderOfADeduplicatedPush(): void
    {
        $holder = $this->newJob(dedupKey: 'k');
        $this->queue()->push($holder);
        $dropped = $this->newJob(dedupKey: 'k');

        self::assertTrue($this->queue()->push($dropped)->wasDeduplicated($dropped->id));
        self::assertSame($holder->id->value, $this->find('k')?->id->value);
    }

    public function testFindSeesAClaimedRowAndReportsItsState(): void
    {
        $new = $this->newJob(dedupKey: 'k', availableAt: CarbonImmutable::now()->subHour());
        $this->queue()->push($new);
        $this->claim();

        $found = $this->find('k');

        self::assertNotNull($found);
        self::assertSame($new->id->value, $found->id->value);
        self::assertSame(State::Claimed, $found->state());
        self::assertSame('w1', $found->lease?->by);
    }

    public function testFindIgnoresACompletedRowBecauseTheKeyIsFreeAgain(): void
    {
        $this->queue()->push($this->newJob(dedupKey: 'k', availableAt: CarbonImmutable::now()->subHour()));
        $claimed = $this->claim();
        $this->queue()->settle($claimed->token, Settlement::complete($claimed->jobs[0]));

        self::assertNull($this->find('k'), 'a finished run no longer holds the key');
    }

    public function testFindIgnoresADiscardedRow(): void
    {
        $this->queue()->push($this->newJob(dedupKey: 'k', availableAt: CarbonImmutable::now()->subHour()));
        $claimed = $this->claim();
        $this->queue()->settle($claimed->token, Settlement::discard($claimed->jobs[0], new Reason(['code' => 'nope'])));

        self::assertNull($this->find('k'));
    }

    public function testFindReturnsTheFreshRowOnceAKeyIsReused(): void
    {
        $first = $this->newJob(dedupKey: 'k', availableAt: CarbonImmutable::now()->subHour());
        $this->queue()->push($first);
        $claimed = $this->claim();
        $this->queue()->settle($claimed->token, Settlement::complete($claimed->jobs[0]));

        $second = $this->newJob(dedupKey: 'k');
        $this->queue()->push($second);

        self::assertSame($second->id->value, $this->find('k')?->id->value, 'the live row wins over the finished one');
    }

    /** Uniqueness is `(pool, dedup_key)`, so the lookup must scope by pool too. */
    public function testFindIsScopedByPool(): void
    {
        $here = $this->newJob(pool: 'emails', dedupKey: 'k');
        $there = $this->newJob(pool: 'other', dedupKey: 'k');
        $this->queue()->push($here, $there);

        self::assertSame($here->id->value, $this->find('k')?->id->value);
        self::assertSame($there->id->value, $this->find('k', 'other')?->id->value);
        self::assertNull($this->find('k', 'empty'));
    }

    public function testFindMatchesANonAsciiKeyExactly(): void
    {
        $new = $this->newJob(dedupKey: 'invoice:Müller:😀');
        $this->queue()->push($new);

        self::assertSame($new->id->value, $this->find('invoice:Müller:😀')?->id->value);
        self::assertNull($this->find('invoice:Muller:😀'), 'no transliteration, no folding');
    }

    public function testDedupKeysReturnsEveryRunOfTheKeyInIdOrder(): void
    {
        $first = $this->newJob(dedupKey: 'k', availableAt: CarbonImmutable::now()->subHour());
        $this->queue()->push($first);
        $claimed = $this->claim();
        $this->queue()->settle($claimed->token, Settlement::complete($claimed->jobs[0]));
        $second = $this->newJob(dedupKey: 'k');
        $this->queue()->push($second);

        $history = $this->monitor()->read($this->poolQuery()->dedupKeys(DedupKey::fromString('k')));

        self::assertSameIds([$first->id, $second->id], $history, 'terminal history included, oldest first');
    }

    public function testDedupKeysFiltersSeveralKeysAtOnce(): void
    {
        $a = $this->newJob(dedupKey: 'a');
        $b = $this->newJob(dedupKey: 'b');
        $c = $this->newJob(dedupKey: 'c');
        $this->queue()->push($a, $b, $c);

        $q = $this->poolQuery()->dedupKeys(DedupKey::fromString('a'), DedupKey::fromString('c'));

        self::assertSameIds([$a->id, $c->id], $this->monitor()->read($q));
        self::assertSame(2, $this->monitor()->count($q));
    }

    public function testDedupKeysComposesWithTheOtherCriteria(): void
    {
        $this->queue()->push($this->newJob(dedupKey: 'k', availableAt: CarbonImmutable::now()->subHour()));
        $claimed = $this->claim();
        $this->queue()->settle($claimed->token, Settlement::complete($claimed->jobs[0]));
        $live = $this->newJob(dedupKey: 'k');
        $this->queue()->push($live);

        $q = $this->poolQuery()->dedupKeys(DedupKey::fromString('k'))->states(State::Claimable)->orderBy(Order::IdDesc);

        self::assertSameIds([$live->id], $this->monitor()->read($q));
    }

    public function testDedupKeysIsScopedByPool(): void
    {
        $here = $this->newJob(pool: 'emails', dedupKey: 'k');
        $this->queue()->push($here, $this->newJob(pool: 'other', dedupKey: 'k'));

        self::assertSameIds([$here->id], $this->monitor()->read($this->poolQuery()->dedupKeys(DedupKey::fromString('k'))));
    }

    public function testAnUnfilteredQueryIsUnaffectedByTheNewCriterion(): void
    {
        $this->queue()->push($this->newJob(dedupKey: 'a'), $this->newJob(dedupKey: 'b'));

        self::assertSame(2, $this->monitor()->count($this->poolQuery()));
    }

    private function find(string $key, string $pool = 'emails'): ?Job
    {
        return $this->monitor()->find(new PoolName($pool), DedupKey::fromString($key));
    }
}
