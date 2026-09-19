<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Support;

use PDO;

/**
 * Records the transaction control a unit under test issues, and answers `inTransaction()` with whatever the test
 * asked for. A real connection is opened (SQLite, in memory) only because `PDO` has no usable constructor-less
 * subclass; no statement ever reaches it.
 */
final class RecordingPdo extends PDO
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private readonly bool $open = false)
    {
        parent::__construct('sqlite::memory:');
    }

    public function inTransaction(): bool
    {
        return $this->open;
    }

    public function beginTransaction(): bool
    {
        $this->calls[] = 'begin';

        return true;
    }

    public function commit(): bool
    {
        $this->calls[] = 'commit';

        return true;
    }

    public function rollBack(): bool
    {
        $this->calls[] = 'rollBack';

        return true;
    }

    public function exec(string $statement): int
    {
        $this->calls[] = $statement;

        return 0;
    }
}
