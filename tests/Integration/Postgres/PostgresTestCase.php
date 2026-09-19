<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Integration\Postgres;

use Lezhnev74\Jobs\Driver\Postgres\PostgresSchema;
use Lezhnev74\Jobs\Tests\Support\PostgresConnection;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class PostgresTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = PostgresConnection::open();
        $schema = new PostgresSchema();
        foreach ([...$schema->dropStatements(), ...$schema->statements()] as $statement) {
            $this->pdo->exec($statement);
        }
    }
}
