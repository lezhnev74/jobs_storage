<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Driver\Mysql;

use Lezhnev74\Jobs\Driver\Mysql\Statements;
use Lezhnev74\Jobs\Tests\Support\RecordingPdo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Which transaction path `transactional()` takes is decided by `inTransaction()` alone, so a recording `PDO`
 * subclass settles it without a server: a top-level call owns begin/commit, a nested one only names savepoints and
 * never touches the caller's transaction.
 */
#[CoversClass(Statements::class)]
#[RequiresPhpExtension('pdo_sqlite')]
final class StatementsTest extends TestCase
{
    public function testATopLevelBlockOwnsTheTransaction(): void
    {
        $pdo = $this->pdo();

        self::assertSame('done', (new Statements($pdo))->transactional(static fn(): string => 'done'));
        self::assertSame(['begin', 'commit'], $pdo->calls);
    }

    public function testATopLevelFailureRollsBackAndRethrows(): void
    {
        $pdo = $this->pdo();

        $this->failing(new Statements($pdo));

        self::assertSame(['begin', 'rollBack'], $pdo->calls);
    }

    public function testANestedBlockReleasesItsSavepointAndLeavesTheCommitToTheCaller(): void
    {
        $pdo = $this->pdo(inTransaction: true);

        self::assertSame('done', (new Statements($pdo))->transactional(static fn(): string => 'done'));

        $name = self::savepointName($pdo->calls[0] ?? '');
        self::assertSame(['SAVEPOINT ' . $name, 'RELEASE SAVEPOINT ' . $name], $pdo->calls);
    }

    public function testANestedFailureRollsBackToTheSavepointAndRethrows(): void
    {
        $pdo = $this->pdo(inTransaction: true);

        $this->failing(new Statements($pdo));

        $name = self::savepointName($pdo->calls[0] ?? '');
        self::assertSame(['SAVEPOINT ' . $name, 'ROLLBACK TO SAVEPOINT ' . $name], $pdo->calls);
    }

    /**
     * A name is never reused on one connection - not even across `Statements` instances, since a connection carries
     * several and `ROLLBACK TO` an earlier name would discard that instance's work.
     */
    public function testEveryNestedBlockOnAConnectionGetsItsOwnSavepointName(): void
    {
        $pdo = $this->pdo(inTransaction: true);

        (new Statements($pdo))->transactional(static fn(): null => null);
        (new Statements($pdo))->transactional(static fn(): null => null);

        $names = array_map(self::savepointName(...), $pdo->calls);
        self::assertCount(2, array_unique($names));
    }

    /** The generated part of a `SAVEPOINT`/`RELEASE`/`ROLLBACK TO` statement - an identifier, never a parameter. */
    private static function savepointName(string $statement): string
    {
        self::assertSame(1, preg_match('/(jobs_sp_[0-9a-f]+)$/', $statement, $m), 'a generated savepoint name');

        return $m[1];
    }

    /** Runs a failing block and asserts the caller saw the original exception - rethrown, never swallowed. */
    private function failing(Statements $statements): void
    {
        $seen = [];

        try {
            $statements->transactional(static fn(): string => throw new RuntimeException('boom'));
        } catch (RuntimeException $e) {
            $seen[] = $e->getMessage();
        }

        self::assertSame(['boom'], $seen, 'the original failure is rethrown unchanged');
    }

    private function pdo(bool $inTransaction = false): RecordingPdo
    {
        return new RecordingPdo($inTransaction);
    }
}
