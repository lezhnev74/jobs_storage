<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Support\Conformance;

use Carbon\CarbonImmutable;
use Lezhnev74\Jobs\Driver\Contract\Driver;
use Lezhnev74\Jobs\Model\PoolName;
use Lezhnev74\Jobs\Model\Settlement;
use Lezhnev74\Jobs\Model\Ttl;
use Lezhnev74\Jobs\Model\WorkerId;
use Lezhnev74\Jobs\Query\ClaimQuery;
use Lezhnev74\Jobs\Query\GroupBy;
use Lezhnev74\Jobs\Query\State;
use Lezhnev74\Jobs\Tests\Support\DriverConformanceTestCase;
use RuntimeException;

/** @phpstan-require-extends DriverConformanceTestCase */
trait ConcurrencyConformance
{
    private const PARALLEL_JOBS = 300;
    private const PARALLEL_WORKERS = 4;

    public function testInterleavedClaimsOnTwoConnectionsAreDisjointAndTokensAreConnectionIndependent(): void
    {
        $ids = $this->pushClaimable(6);
        $a = $this->queue();
        $b = $this->connect()->queue();
        $q = ClaimQuery::pool(new PoolName('emails'))->limit(2);

        $first = $a->claim($q, new WorkerId('a'), Ttl::minutes(5));
        $second = $b->claim($q, new WorkerId('b'), Ttl::minutes(5));
        $third = $a->claim($q, new WorkerId('a'), Ttl::minutes(5));

        self::assertSameIds([$ids[0], $ids[1]], $first->jobs);
        self::assertSameIds([$ids[2], $ids[3]], $second->jobs);
        self::assertSameIds([$ids[4], $ids[5]], $third->jobs);
        self::assertTrue($b->settle($first->token, Settlement::complete($first->jobs[0]))->isAcked($ids[0]), 'the token fences, not the connection');
        self::assertTrue($b->heartbeat($third->token, Ttl::minutes(1), $ids[4])->isAcked($ids[4]));
        self::assertTrue($a->settle($second->token, Settlement::release($second->jobs[0]))->isAcked($ids[2]));
    }

    /**
     * The unique index is the arbiter of a dedup race: two connections pushing one key insert exactly one row, and
     * both reports say which. No application-level locking, and - on a backend whose insert would otherwise lock the
     * duplicate record - no waiting either.
     */
    public function testTwoConnectionsPushingOneKeyInsertExactlyOneRowAndBothReportsAreHonest(): void
    {
        $first = $this->newJob(dedupKey: 'contended');
        $second = $this->newJob(dedupKey: 'contended');

        $a = $this->queue()->push($first);
        $b = $this->connect()->queue()->push($second);

        self::assertTrue($a->wasInserted($first->id), 'the first push landed');
        self::assertTrue($b->wasDeduplicated($second->id), 'the second learned it was dropped');
        self::assertSame(1, $this->monitor()->count($this->poolQuery()));
        self::assertNull($this->monitor()->get($second->id), 'the dropped job leaves no row');
    }

    /** A push must never block behind an open claim, whatever the collision: the holder is settled on another connection. */
    public function testACollidingPushDoesNotWaitOnAClaimHeldByAnotherConnection(): void
    {
        $stored = $this->newJob(dedupKey: 'held', availableAt: CarbonImmutable::now()->subHour());
        $this->queue()->push($stored);
        $claimed = $this->claim();

        $colliding = $this->newJob(dedupKey: 'held');
        $report = $this->connect()->queue()->push($colliding);

        self::assertTrue($report->wasDeduplicated($colliding->id));
        self::assertTrue($this->queue()->settle($claimed->token, Settlement::complete($claimed->jobs[0]))->isAcked($stored->id));
    }

    public function testParallelClaimersNeverShareAJob(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('pcntl and posix are required to fork worker processes');
        }
        $this->pushClaimable(self::PARALLEL_JOBS);

        $claimedIds = [];
        foreach ($this->forkWorkers() as $worker => $file) {
            $claimedIds[$worker] = self::readIds($file);
        }

        $all = array_merge(...array_values($claimedIds));
        self::assertCount(self::PARALLEL_JOBS, $all, 'every job was claimed exactly once');
        self::assertCount(self::PARALLEL_JOBS, array_unique($all), 'no job went to two workers');
        self::assertGreaterThan(1, \count(array_filter($claimedIds, static fn(array $ids): bool => $ids !== [])), 'the work was actually shared');
        self::assertSame(self::PARALLEL_JOBS, $this->monitor()->count($this->poolQuery()->states(State::Claimed)));
        self::assertSame([['group' => 'claimed', 'count' => self::PARALLEL_JOBS]], $this->monitor()->aggregate($this->poolQuery(), GroupBy::State));
    }

    /** @return array<int, string> worker index => file with one job id per line */
    private function forkWorkers(): array
    {
        $files = [];
        $pids = [];
        for ($worker = 0; $worker < self::PARALLEL_WORKERS; ++$worker) {
            $file = tempnam(sys_get_temp_dir(), 'jobs-claim-');
            if ($file === false) {
                throw new RuntimeException('cannot create a temp file for the worker output');
            }
            $files[$worker] = $file;
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('cannot fork a worker process');
            }
            if ($pid === 0) {
                $this->runWorkerProcess($worker, $file);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        return $files;
    }

    /**
     * SIGKILL, so the child neither runs PHPUnit's teardown nor closes the connections it inherited from the parent.
     */
    private function runWorkerProcess(int $worker, string $file): never
    {
        try {
            file_put_contents($file, self::drainPool($this->connect(), 'worker-' . $worker));
        } finally {
            posix_kill(posix_getpid(), SIGKILL);
            exit(1);
        }
    }

    private static function drainPool(Driver $driver, string $worker): string
    {
        $queue = $driver->queue();
        $q = ClaimQuery::pool(new PoolName('emails'))->limit(7);
        $lines = '';
        do {
            $claimed = $queue->claim($q, new WorkerId($worker), Ttl::minutes(5));
            foreach ($claimed->ids() as $id) {
                $lines .= $id->value . "\n";
            }
        } while (!$claimed->isEmpty());

        return $lines;
    }

    /** @return list<string> */
    private static function readIds(string $file): array
    {
        $content = file_get_contents($file);
        unlink($file);
        self::assertNotFalse($content, 'the worker wrote its ids');

        return array_values(array_filter(explode("\n", $content), static fn(string $line): bool => $line !== ''));
    }
}
