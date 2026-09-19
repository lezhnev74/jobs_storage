<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Integration\Postgres;

use PDOException;

/**
 * The partial unique index is what makes dedup "only while in flight"; the driver's `ON CONFLICT DO NOTHING` relies
 * on it, so the index is asserted directly rather than only through the queue.
 */
final class DedupIndexTest extends PostgresTestCase
{
    private const UNIQUE_VIOLATION = '23505';

    public function testASecondPendingRowWithTheSameKeyIsRejected(): void
    {
        $this->insert('k');

        try {
            $this->insert('k');
            self::fail('the partial unique index must reject a second non-terminal row');
        } catch (PDOException $e) {
            self::assertSame(self::UNIQUE_VIOLATION, $e->getCode());
        }
    }

    public function testTheSameKeyIsFreeOnceTheFirstRowIsCompleted(): void
    {
        $this->insert('k');
        $this->pdo->exec('UPDATE jobs SET completed_at = now()');

        $this->insert('k');

        self::assertSame(2, $this->rowCount());
    }

    public function testTheSameKeyIsFreeOnceTheFirstRowIsDiscarded(): void
    {
        $this->insert('k');
        $this->pdo->exec('UPDATE jobs SET discarded_at = now()');

        $this->insert('k');

        self::assertSame(2, $this->rowCount());
    }

    public function testSettlingTheSecondRowLeavesBothOutsideTheConstraint(): void
    {
        $this->insert('k');
        $this->pdo->exec('UPDATE jobs SET completed_at = now()');
        $this->insert('k');
        $this->pdo->exec('UPDATE jobs SET discarded_at = now() WHERE completed_at IS NULL');

        $this->insert('k');

        self::assertSame(3, $this->rowCount());
    }

    public function testTheSameKeyInAnotherPoolIsIndependent(): void
    {
        $this->insert('k', pool: 'p1');
        $this->insert('k', pool: 'p2');

        self::assertSame(2, $this->rowCount());
    }

    public function testDedupKeyIsNotNullable(): void
    {
        $this->expectException(PDOException::class);

        $this->pdo->exec("INSERT INTO jobs (id, name, pool, available_at) VALUES (gen_random_uuid(), 'a.b', 'p', now())");
    }

    private function insert(string $dedupKey, string $pool = 'p'): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO jobs (id, name, pool, available_at, dedup_key)'
            . " VALUES (gen_random_uuid(), 'a.b', ?, now(), ?)",
        );
        $statement->execute([$pool, $dedupKey]);
    }

    private function rowCount(): int
    {
        $count = $this->pdo->prepare('SELECT count(*) FROM jobs');
        $count->execute();

        return (int) $count->fetchColumn();
    }
}
