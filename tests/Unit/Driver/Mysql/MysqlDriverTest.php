<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Driver\Mysql;

use Lezhnev74\Jobs\Driver\Mysql\MysqlDriver;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * The constructor applies session settings a stand-in connection cannot accept, so only the error-mode guard - which
 * runs before them - is unit-testable. Everything past it belongs to the conformance suite.
 */
#[CoversClass(MysqlDriver::class)]
#[RequiresPhpExtension('pdo_sqlite')]
final class MysqlDriverTest extends TestCase
{
    public function testRejectsAConnectionThatDoesNotThrowOnErrors(): void
    {
        $this->expectExceptionMessage('PDO::ATTR_ERRMODE');

        new MysqlDriver(new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]));
    }
}
