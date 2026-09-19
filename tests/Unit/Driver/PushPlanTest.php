<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Driver;

use Lezhnev74\Jobs\Driver\Support\PushPlan;
use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Model\JobName;
use Lezhnev74\Jobs\Model\NewJob;
use Lezhnev74\Jobs\Model\PoolName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The client-side half of the MySQL push: what the probe found decides what the insert is allowed to touch, so a
 * colliding row never has to be locked to discover the collision.
 */
#[CoversClass(PushPlan::class)]
final class PushPlanTest extends TestCase
{
    public function testAnUncontestedBatchSurvivesWhole(): void
    {
        $a = self::job('ka');
        $b = self::job('kb');

        $plan = PushPlan::of([$a, $b], [], []);

        self::assertSame([$a, $b], $plan->survivors);
        self::assertSame([], $plan->dropped);
        self::assertSame([$a->id, $b->id], $plan->survivorIds());
    }

    public function testAJobWhoseIdAlreadyExistsIsDropped(): void
    {
        $a = self::job('ka');
        $b = self::job('kb');

        $plan = PushPlan::of([$a, $b], [$a->id->value], []);

        self::assertSame([$b], $plan->survivors);
        self::assertSame([$a->id], $plan->dropped);
    }

    /** The probe returns whatever case the backend stored; ids compare case-insensitively everywhere else too. */
    public function testAnExistingIdMatchesRegardlessOfCase(): void
    {
        $a = self::job('ka');

        $plan = PushPlan::of([$a], [strtoupper($a->id->value)], []);

        self::assertSame([], $plan->survivors);
        self::assertSame([$a->id], $plan->dropped);
    }

    public function testAJobWhoseKeyIsHeldIsDropped(): void
    {
        $a = self::job('ka');
        $b = self::job('kb');

        $plan = PushPlan::of([$a, $b], [], [['emails', 'ka']]);

        self::assertSame([$b], $plan->survivors);
        self::assertSame([$a->id], $plan->dropped);
    }

    /** Uniqueness is per pool, so a key held elsewhere says nothing about this one. */
    public function testAKeyHeldInAnotherPoolDoesNotDrop(): void
    {
        $a = self::job('ka');

        $plan = PushPlan::of([$a], [], [['other', 'ka']]);

        self::assertSame([$a], $plan->survivors);
        self::assertSame([], $plan->dropped);
    }

    public function testTheFirstCopyOfAKeyInsideTheBatchWins(): void
    {
        $first = self::job('k');
        $second = self::job('k');
        $third = self::job('k');

        $plan = PushPlan::of([$first, $second, $third], [], []);

        self::assertSame([$first], $plan->survivors);
        self::assertSame([$second->id, $third->id], $plan->dropped);
    }

    public function testARepeatedIdInsideTheBatchIsDroppedOnceItIsClaimedByItsFirstCopy(): void
    {
        $id = JobId::new();
        $first = self::job('ka', $id);
        $second = self::job('kb', $id);

        $plan = PushPlan::of([$first, $second], [], []);

        self::assertSame([$first], $plan->survivors);
        self::assertSame([$second->id], $plan->dropped);
    }

    /** A pool whose name ends where a key begins must not be mistaken for the other way round. */
    public function testThePoolAndKeyNamespacesDoNotBleedIntoEachOther(): void
    {
        $job = self::job('b:k', pool: 'a');

        $plan = PushPlan::of([$job], [], [['a:b', 'k']]);

        self::assertSame([$job], $plan->survivors);
    }

    public function testAFullyCoveredBatchLeavesNoSurvivors(): void
    {
        $a = self::job('ka');

        $plan = PushPlan::of([$a], [], [['emails', 'ka']]);

        self::assertSame([], $plan->survivors);
        self::assertSame([], $plan->survivorIds());
    }

    public function testAnEmptyBatchPlansNothing(): void
    {
        $plan = PushPlan::of([], ['whatever'], [['emails', 'ka']]);

        self::assertSame([], $plan->survivors);
        self::assertSame([], $plan->dropped);
    }

    private static function job(string $dedupKey, ?JobId $id = null, string $pool = 'emails'): NewJob
    {
        return (new NewJob($id ?? JobId::new(), new JobName('a.b'), new PoolName($pool)))->dedup($dedupKey);
    }
}
