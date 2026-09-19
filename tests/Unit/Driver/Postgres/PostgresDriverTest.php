<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Driver\Postgres;

use InvalidArgumentException;
use Lezhnev74\Jobs\Driver\Postgres\PostgresDriver;
use Lezhnev74\Jobs\Driver\Postgres\PostgresMonitor;
use Lezhnev74\Jobs\Driver\Postgres\PostgresQueue;
use Lezhnev74\Jobs\Driver\Postgres\PostgresSchema;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[CoversClass(PostgresDriver::class)]
#[RequiresPhpExtension('pdo_sqlite')]
final class PostgresDriverTest extends TestCase
{
    public function testWiresQueueMonitorAndSchemaOverTheConnection(): void
    {
        $driver = new PostgresDriver(new PDO('sqlite::memory:'));

        self::assertInstanceOf(PostgresQueue::class, $driver->queue());
        self::assertInstanceOf(PostgresMonitor::class, $driver->monitor());
        self::assertInstanceOf(PostgresSchema::class, $driver->schema());
    }

    public function testRejectsAConnectionThatDoesNotThrowOnErrors(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PostgresDriver(new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]));
    }
}
