<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Mysql;

use InvalidArgumentException;
use Lezhnev74\Jobs\Contract\JobMonitor;
use Lezhnev74\Jobs\Contract\JobQueue;
use Lezhnev74\Jobs\Driver\Contract\Driver;
use Lezhnev74\Jobs\Driver\Contract\Schema;
use PDO;
use PDOException;

/**
 * MySQL 8.0.19+ over a caller-owned `PDO`. Both required attributes are asserted in the constructor because a wrong
 * one silently changes behavior:
 *
 * - `PDO::ATTR_ERRMODE = PDO::ERRMODE_EXCEPTION` - the driver reports nothing through return values;
 * - `PDO::ATTR_EMULATE_PREPARES = false` - native prepares bind `LIMIT ?` as an integer.
 *
 * `PDO::MYSQL_ATTR_FOUND_ROWS = true` is recommended but not required: it makes `rowCount()` mean "matched", which
 * lets `heartbeat` skip its second statement. `pdo_mysql` cannot read that attribute back, so it is not asserted,
 * and without it the heartbeat merely re-selects more often.
 *
 * `time_zone = '+00:00'` and READ COMMITTED are applied here, once per connection: they cannot be asserted cheaply
 * and must not be assumed from `my.cnf`.
 *
 * MySQL has no `UPDATE ... RETURNING`, so `push`, `claim`, `settle` and the janitor verbs are each one short
 * transaction rather than one statement. Called inside a transaction the caller owns, each nests on a SAVEPOINT and
 * leaves the commit to the caller - the side-effect `push` ("write the order, enqueue the email, commit together")
 * is what this is for. Two caveats when nesting:
 *
 * - **isolation is the caller's.** READ COMMITTED is set above with `SET SESSION`, so it holds for a transaction the
 *   caller opens afterwards; a caller who overrides it, or constructs this driver mid-transaction, gets their level.
 *   Under REPEATABLE READ `push`'s collision probe reads a stale snapshot, so it lets a colliding job through and the
 *   unique index catches it: the report stays correct, only the lock avoidance is lost.
 * - **locks last as long as the caller's transaction.** A nested `claim` or `settle` holds its row locks until the
 *   caller commits, which can stall other workers. That is the caller's trade to make; only `push` has a use case
 *   for it.
 *
 * `heartbeat` and stale-ack telemetry are autocommit statements and silently join a caller transaction.
 */
final class MysqlDriver implements Driver
{
    public function __construct(private readonly PDO $pdo)
    {
        self::assertAttribute($pdo, PDO::ATTR_ERRMODE, [PDO::ERRMODE_EXCEPTION], 'PDO::ATTR_ERRMODE = PDO::ERRMODE_EXCEPTION');
        self::assertAttribute($pdo, PDO::ATTR_EMULATE_PREPARES, [0, false], 'PDO::ATTR_EMULATE_PREPARES = false');

        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }

    public function queue(): JobQueue
    {
        return new MysqlQueue($this->pdo);
    }

    public function monitor(): JobMonitor
    {
        return new MysqlMonitor($this->pdo);
    }

    public function schema(): Schema
    {
        return new MysqlSchema();
    }

    /**
     * The read-back spelling of a value is the PDO driver's business, not the caller's: `pdo_mysql` reports
     * `ATTR_EMULATE_PREPARES = false` as `int(0)` up to PHP 8.3 and as `bool(false)` from 8.4 on, so each
     * requirement lists every spelling that satisfies it. An attribute the PDO driver cannot report at all passes:
     * the guard is here to catch a misconfigured connection, not to reject one whose driver simply answers no
     * questions.
     *
     * @param list<int|bool> $accepted
     */
    private static function assertAttribute(PDO $pdo, int $attribute, array $accepted, string $requirement): void
    {
        try {
            $actual = $pdo->getAttribute($attribute);
        } catch (PDOException) {
            return;
        }
        if (!\in_array($actual, $accepted, true)) {
            throw new InvalidArgumentException('MysqlDriver requires ' . $requirement);
        }
    }
}
