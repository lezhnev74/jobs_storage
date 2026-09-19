<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Integration\Mysql;

use Lezhnev74\Jobs\Contract\JobQueue;
use Lezhnev74\Jobs\Driver\Mysql\MysqlDriver;
use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Model\JobName;
use Lezhnev74\Jobs\Model\NewJob;
use Lezhnev74\Jobs\Model\PoolName;
use Lezhnev74\Jobs\Model\PushReport;
use Lezhnev74\Jobs\Tests\Support\MysqlConnection;
use PDO;

/**
 * An InnoDB insert that hits a duplicate takes a lock on the duplicate record and waits for whoever holds it, so a
 * colliding push would block behind an open `claim` or `settle`. Postgres' `DO NOTHING` never waits; the MySQL driver
 * matches it by filtering the batch against a non-locking probe before the insert. Asserted by holding the colliding
 * row under `FOR UPDATE` on a second connection with a lock wait timeout far below the test's patience: a push that
 * waited would raise, one that filtered returns.
 */
final class PushLockingTest extends MysqlTestCase
{
    private const LOCK_WAIT_SECONDS = 1;

    private PDO $holder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->holder = MysqlConnection::open();
    }

    protected function tearDown(): void
    {
        if ($this->holder->inTransaction()) {
            $this->holder->rollBack();
        }

        parent::tearDown();
    }

    public function testADedupCollisionWithALockedRowIsReportedWithoutWaiting(): void
    {
        $stored = $this->job('k');
        $this->queue()->push($stored);
        $this->lock($stored->id);

        $colliding = $this->job('k');

        self::assertTrue($this->push($colliding)->wasDeduplicated($colliding->id));
    }

    public function testARePushOfALockedIdIsReportedWithoutWaiting(): void
    {
        $stored = $this->job('k');
        $this->queue()->push($stored);
        $this->lock($stored->id);

        $rePush = new NewJob($stored->id, new JobName('other'), new PoolName('emails'));

        self::assertTrue($this->push($rePush)->wasDeduplicated($stored->id));
    }

    /** The one job of the batch that collides must not stop the rest from landing while the holder is open. */
    public function testTheUncontestedJobsOfABatchStillLandWhileTheCollidingRowIsLocked(): void
    {
        $stored = $this->job('k');
        $this->queue()->push($stored);
        $this->lock($stored->id);

        $colliding = $this->job('k');
        $fresh = $this->job('free');

        $report = $this->push($colliding, $fresh);

        self::assertTrue($report->wasDeduplicated($colliding->id));
        self::assertTrue($report->wasInserted($fresh->id));
    }

    /** A push into a locked-free pool must stay unaffected by the holder. */
    public function testAnUncontestedPushIsUnaffectedByAnOpenHolder(): void
    {
        $stored = $this->job('k');
        $this->queue()->push($stored);
        $this->lock($stored->id);

        $fresh = $this->job('free');

        self::assertTrue($this->push($fresh)->wasInserted($fresh->id));
    }

    /** Pushes on a connection whose lock wait is short enough that any wait at all fails the test. */
    private function push(NewJob ...$jobs): PushReport
    {
        $pdo = MysqlConnection::open();
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = ' . self::LOCK_WAIT_SECONDS);

        return (new MysqlDriver($pdo))->queue()->push(...$jobs);
    }

    /** Holds an X lock on the row for the rest of the test, the way an open claim or settle would. */
    private function lock(JobId $id): void
    {
        $this->holder->beginTransaction();
        $select = $this->holder->prepare('SELECT id FROM jobs WHERE id = UUID_TO_BIN(?) FOR UPDATE');
        $select->execute([$id->value]);
        self::assertCount(1, $select->fetchAll(), 'the holder locked the row');
    }

    private function queue(): JobQueue
    {
        return (new MysqlDriver($this->pdo))->queue();
    }

    private function job(string $dedupKey): NewJob
    {
        return (new NewJob(JobId::new(), new JobName('billing.invoice.send'), new PoolName('emails')))->dedup($dedupKey);
    }
}
