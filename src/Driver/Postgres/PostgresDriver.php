<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Postgres;

use InvalidArgumentException;
use Lezhnev74\Jobs\Contract\JobMonitor;
use Lezhnev74\Jobs\Contract\JobQueue;
use Lezhnev74\Jobs\Driver\Contract\Driver;
use Lezhnev74\Jobs\Driver\Contract\Schema;
use PDO;

/**
 * The bundled PostgreSQL 14+ driver over a caller-owned `PDO` (`pgsql:` DSN). Expected connection attributes:
 *
 * - `PDO::ATTR_ERRMODE = PDO::ERRMODE_EXCEPTION` (PHP 8 default) - the driver reports nothing through return values;
 * - `PDO::ATTR_EMULATE_PREPARES = false` (pdo_pgsql default) - statements rely on server-side `?::type` casts and
 *   fixed-shape array parameters, which only native prepares send correctly.
 *
 * Every call is one autocommit statement; the connection must not be inside a transaction the caller controls.
 */
final class PostgresDriver implements Driver
{
    public function __construct(private readonly PDO $pdo)
    {
        if ($pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new InvalidArgumentException('PostgresDriver requires PDO::ATTR_ERRMODE = PDO::ERRMODE_EXCEPTION');
        }
    }

    public function queue(): JobQueue
    {
        return new PostgresQueue($this->pdo);
    }

    public function monitor(): JobMonitor
    {
        return new PostgresMonitor($this->pdo);
    }

    public function schema(): Schema
    {
        return new PostgresSchema();
    }
}
