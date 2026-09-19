<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Integration\Mysql;

use Lezhnev74\Jobs\Driver\Contract\Driver;
use Lezhnev74\Jobs\Driver\Mysql\MysqlDriver;
use Lezhnev74\Jobs\Tests\Support\DriverConformanceTestCase;
use Lezhnev74\Jobs\Tests\Support\MysqlConnection;
use PDO;

/**
 * Drives the conformance suite the way a third-party driver package would: a fresh connection per `connect()`, one
 * process-wide connection for the DDL.
 */
final class MysqlConformanceTest extends DriverConformanceTestCase
{
    private static ?PDO $ddl = null;

    protected function connect(): Driver
    {
        return new MysqlDriver(MysqlConnection::open());
    }

    protected function execute(string $statement): void
    {
        (self::$ddl ??= MysqlConnection::open())->exec($statement);
    }
}
