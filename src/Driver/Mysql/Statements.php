<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Mysql;

use Lezhnev74\Jobs\Model\JobId;
use PDO;
use PDOStatement;
use Throwable;

final class Statements
{
    /**
     * Distinguishes nested savepoints on one connection; names are generated, never taken from a caller. Random
     * rather than a counter: a connection carries several `Statements` (queue and monitor, one per call site), and
     * `ROLLBACK TO` a name an earlier instance already used would discard that instance's work too.
     */
    private const SAVEPOINT_ENTROPY_BYTES = 8;

    public function __construct(private readonly PDO $pdo) {}

    public function run(Sql $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql->text);
        $statement->execute($sql->params);

        return $statement;
    }

    /** @return list<string> */
    public function ids(Sql $sql): array
    {
        return array_values(array_filter($this->run($sql)->fetchAll(PDO::FETCH_COLUMN), \is_string(...)));
    }

    /** @return list<array<string, mixed>> */
    public function rows(Sql $sql): array
    {
        return array_values(array_filter($this->run($sql)->fetchAll(PDO::FETCH_ASSOC), \is_array(...)));
    }

    /**
     * `$act` runs inside the transaction on the locked ids; an empty pick rolls back, since nothing was written.
     * Nested in a caller's transaction that rollback reaches the savepoint only, never the caller's own work.
     *
     * @template T
     * @param callable(list<JobId>): T $act
     * @param T $whenNothingPicked
     * @return T
     */
    public function lockThenAct(Sql $pick, callable $act, mixed $whenNothingPicked): mixed
    {
        return $this->transactional(function () use ($pick, $act, $whenNothingPicked): mixed {
            $ids = array_map(JobId::fromString(...), $this->ids($pick));

            return $ids === [] ? $whenNothingPicked : $act($ids);
        });
    }

    /**
     * One short transaction, committed before the call returns; nothing is ever held open between calls.
     *
     * Inside a transaction the caller owns, the block nests on a SAVEPOINT instead: the driver releases its own
     * savepoint and leaves the commit - and any wider rollback - to the caller. A failure rolls back to the
     * savepoint and rethrows, so the caller's transaction survives and stays theirs to finish. Intended for the
     * side-effect push ("write the order, enqueue the email, commit together"); `claim`/`settle` nest too, but they
     * then hold their row locks until the caller commits, which is no longer one short transaction.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transactional(callable $work): mixed
    {
        return $this->pdo->inTransaction() ? $this->nested($work) : $this->owned($work);
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function owned(callable $work): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $work();
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
        $this->pdo->commit();

        return $result;
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function nested(callable $work): mixed
    {
        $savepoint = 'jobs_sp_' . bin2hex(random_bytes(self::SAVEPOINT_ENTROPY_BYTES));
        $this->pdo->exec('SAVEPOINT ' . $savepoint);

        try {
            $result = $work();
        } catch (Throwable $e) {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);

            throw $e;
        }
        $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);

        return $result;
    }
}
