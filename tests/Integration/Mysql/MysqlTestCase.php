<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Integration\Mysql;

use Lezhnev74\Jobs\Driver\Mysql\MysqlSchema;
use Lezhnev74\Jobs\Tests\Support\MysqlConnection;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class MysqlTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = MysqlConnection::open();
        $schema = new MysqlSchema();
        foreach ([...$schema->dropStatements(), ...$schema->statements()] as $statement) {
            $this->pdo->exec($statement);
        }
    }
}
