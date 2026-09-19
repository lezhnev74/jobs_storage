<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Integration\Mysql;

use Lezhnev74\Jobs\Contract\JobQueue;
use Lezhnev74\Jobs\Driver\Mysql\MysqlDriver;
use Lezhnev74\Jobs\Driver\Mysql\Sql;
use Lezhnev74\Jobs\Driver\Mysql\Statements;
use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Model\JobName;
use Lezhnev74\Jobs\Model\NewJob;
use Lezhnev74\Jobs\Model\PoolName;
use Lezhnev74\Jobs\Model\Settlement;
use Lezhnev74\Jobs\Model\Ttl;
use Lezhnev74\Jobs\Model\WorkerId;
use Lezhnev74\Jobs\Query\ClaimQuery;
use RuntimeException;

/**
 * Enqueueing as a side effect of a business transaction is the normal way to use a queue, so a driver call made
 * inside a transaction the caller owns nests on a SAVEPOINT instead of refusing. What that must buy: the write
 * commits and rolls back with the caller's own, and a failed nested call leaves the outer transaction usable.
 * MySQL-only - Postgres has one autocommit statement per call and no transaction to nest in.
 */
final class NestedTransactionTest extends MysqlTestCase
{
    public function testAPushInsideACallerTransactionLandsOnTheCallerCommit(): void
    {
        $job = $this->job();

        $this->pdo->beginTransaction();
        self::assertTrue($this->queue()->push($job)->wasInserted($job->id));
        $this->pdo->commit();

        self::assertSame(1, $this->rowCount());
    }

    public function testAPushInsideACallerTransactionVanishesOnTheCallerRollback(): void
    {
        $this->pdo->beginTransaction();
        $this->queue()->push($this->job());
        $this->pdo->rollBack();

        self::assertSame(0, $this->rowCount());
    }

    /**
     * The savepoint rollback must reach the failed block only, leaving the caller's earlier work intact. The model
     * guards every column, so no public call can be made to fail server-side: the failure is raised in a nested
     * block directly, which is the contract `transactional()` states.
     */
    public function testAFailedNestedCallLeavesTheOuterTransactionUsable(): void
    {
        $statements = new Statements($this->pdo);
        $seen = [];

        $this->pdo->beginTransaction();
        $this->queue()->push($this->job());

        try {
            $statements->transactional(function () use ($statements): never {
                $statements->run(new Sql('INSERT INTO jobs (id, name, pool, dedup_key, available_at) VALUES (UUID_TO_BIN(?), ?, ?, ?, NOW(6))', [JobId::new()->value, 'doomed', 'emails', 'doomed']));

                throw new RuntimeException('the caller work fails after the write');
            });
        } catch (RuntimeException $e) {
            $seen[] = $e->getMessage();
        }
        self::assertSame(['the caller work fails after the write'], $seen, 'the failure is rethrown');

        $this->queue()->push($this->job());
        $this->pdo->commit();

        self::assertSame(2, $this->rowCount(), 'the failed block rolled back to its savepoint, nothing else');
    }

    /** Claim and settle nest too: worker-side, but they must behave exactly as at top level. */
    public function testClaimAndSettleNestedBehaveAsAtTopLevel(): void
    {
        $job = $this->job();
        $this->queue()->push($job);

        $this->pdo->beginTransaction();
        $claimed = $this->queue()->claim(ClaimQuery::pool(new PoolName('emails')), new WorkerId('w'), Ttl::minutes(5));
        self::assertCount(1, $claimed->jobs);
        self::assertTrue($this->queue()->settle($claimed->token, Settlement::complete($claimed->jobs[0]))->isAcked($job->id));
        $this->pdo->commit();

        self::assertSame(1, $this->completedCount());
    }

    /** An empty claim rolls back to the savepoint, which must not discard the caller's write. */
    public function testAnEmptyClaimDoesNotRollBackTheCallerWork(): void
    {
        $job = $this->job();

        $this->pdo->beginTransaction();
        $this->queue()->push($job);
        $this->queue()->claim(ClaimQuery::pool(new PoolName('other')), new WorkerId('w'), Ttl::minutes(5));
        $this->pdo->commit();

        self::assertSame(1, $this->rowCount());
    }

    private function queue(): JobQueue
    {
        return (new MysqlDriver($this->pdo))->queue();
    }

    /** A distinct dedup key per job: the default is a content hash, so two identical jobs would deduplicate. */
    private function job(): NewJob
    {
        $job = new NewJob(JobId::new(), new JobName('billing.invoice.send'), new PoolName('emails'));

        return $job->dedup($job->id->value);
    }

    private function rowCount(): int
    {
        return $this->rowsMatching('SELECT COUNT(*) FROM jobs');
    }

    private function completedCount(): int
    {
        return $this->rowsMatching('SELECT COUNT(*) FROM jobs WHERE completed_at IS NOT NULL');
    }

    private function rowsMatching(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement, 'the count query must run');

        return (int) $statement->fetchColumn();
    }
}
