<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Contract;

/**
 * The library never executes this DDL at runtime: the conformance suite applies it to a throwaway test database, and
 * operators apply it manually in real deployments, where index and autovacuum settings are theirs to review.
 *
 * `dropStatements()` exists for the conformance suite, which rebuilds the schema before every test; a truncate would
 * require knowing table names this interface deliberately does not expose. Both lists must be safe to run in any
 * prior state (`IF NOT EXISTS` / `IF EXISTS`) so a crashed run never leaves a database that cannot be reset.
 */
interface Schema
{
    /** @return list<string> */
    public function statements(): array;

    /**
     * Removes everything `statements()` created; a no-op on an empty database.
     *
     * @return list<string>
     */
    public function dropStatements(): array;
}
