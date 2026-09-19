<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Integration\Mysql;

use PDO;
use PDOException;

/**
 * InnoDB has no partial index, so non-terminal uniqueness is emulated by a generated column that nulls itself on
 * settlement. That emulation is the one construct with no PostgreSQL counterpart: it gets its own test.
 */
final class DedupIndexTest extends MysqlTestCase
{
    private const UNIQUE_VIOLATION = '23000';

    public function testASecondPendingRowWithTheSameKeyIsRejected(): void
    {
        $this->insert('k');

        try {
            $this->insert('k');
            self::fail('the unique key over the generated slot must reject a second non-terminal row');
        } catch (PDOException $e) {
            self::assertSame(self::UNIQUE_VIOLATION, $e->getCode());
        }
    }

    public function testTheSameKeyIsFreeOnceTheFirstRowIsCompleted(): void
    {
        $this->insert('k');
        $this->pdo->exec('UPDATE jobs SET completed_at = NOW(6)');

        $this->insert('k');

        self::assertSame(2, $this->rowCount());
    }

    public function testTheSameKeyIsFreeOnceTheFirstRowIsDiscarded(): void
    {
        $this->insert('k');
        $this->pdo->exec('UPDATE jobs SET discarded_at = NOW(6)');

        $this->insert('k');

        self::assertSame(2, $this->rowCount());
    }

    public function testSettlingTheSecondRowLeavesBothOutsideTheConstraint(): void
    {
        $this->insert('k');
        $this->pdo->exec('UPDATE jobs SET completed_at = NOW(6)');
        $this->insert('k');
        $this->pdo->exec('UPDATE jobs SET discarded_at = NOW(6) WHERE completed_at IS NULL');

        $this->insert('k');

        self::assertSame(3, $this->rowCount());
    }

    public function testTheSameKeyInAnotherPoolIsIndependent(): void
    {
        $this->insert('k', pool: 'p1');
        $this->insert('k', pool: 'p2');

        self::assertSame(2, $this->rowCount());
    }

    /** The slot is maintained on UPDATE, not only on INSERT: reviving a terminal row re-enters the constraint. */
    public function testTheSlotIsRecomputedWhenARowLeavesTheTerminalState(): void
    {
        $this->insert('k');
        $this->pdo->exec('UPDATE jobs SET completed_at = NOW(6)');
        $this->insert('k');

        $this->expectException(PDOException::class);

        $this->pdo->exec('UPDATE jobs SET completed_at = NULL WHERE completed_at IS NOT NULL');
    }

    public function testANonAsciiKeyIsStoredVerbatimAndCollides(): void
    {
        $this->insert('invoice:Müller:😀');
        $keys = $this->pdo->prepare('SELECT dedup_key FROM jobs');
        $keys->execute();
        self::assertSame(['invoice:Müller:😀'], $keys->fetchAll(PDO::FETCH_COLUMN));

        $this->expectException(PDOException::class);

        $this->insert('invoice:Müller:😀');
    }

    public function testDedupKeyIsNotNullable(): void
    {
        $this->expectException(PDOException::class);

        $this->pdo->exec("INSERT INTO jobs (id, name, pool, available_at) VALUES (UUID_TO_BIN(UUID()), 'a.b', 'p', NOW(6))");
    }

    private function insert(string $dedupKey, string $pool = 'p'): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO jobs (id, name, pool, available_at, dedup_key)'
            . " VALUES (UUID_TO_BIN(UUID()), 'a.b', ?, NOW(6), ?)",
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
