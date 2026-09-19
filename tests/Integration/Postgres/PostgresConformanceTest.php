<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Integration\Postgres;

use Lezhnev74\Jobs\Driver\Contract\Driver;
use Lezhnev74\Jobs\Driver\Postgres\PostgresDriver;
use Lezhnev74\Jobs\Tests\Support\DriverConformanceTestCase;
use Lezhnev74\Jobs\Tests\Support\PostgresConnection;
use PDO;

/**
 * Drives the conformance suite the way a third-party driver package would: a fresh connection per `connect()`, one
 * process-wide connection for the DDL.
 */
final class PostgresConformanceTest extends DriverConformanceTestCase
{
    private static ?PDO $ddl = null;

    protected function connect(): Driver
    {
        return new PostgresDriver(PostgresConnection::open());
    }

    protected function execute(string $statement): void
    {
        (self::$ddl ??= PostgresConnection::open())->exec($statement);
    }
}
