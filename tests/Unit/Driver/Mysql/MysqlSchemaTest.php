<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Driver\Mysql;

use Lezhnev74\Jobs\Driver\Mysql\MysqlSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MysqlSchema::class)]
final class MysqlSchemaTest extends TestCase
{
    public function testStatementsAreTheBundledDdlInOrder(): void
    {
        $statements = (new MysqlSchema())->statements();

        self::assertCount(1, $statements, 'MySQL declares the index inside CREATE TABLE');
        self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS jobs (', $statements[0]);
        self::assertStringContainsString('INDEX jobs_claim_idx (pool, completed_at, discarded_at, available_at)', $statements[0]);
        self::assertStringContainsString('UNIQUE KEY jobs_dedup_uk (pool, dedup_slot)', $statements[0]);
        self::assertStringEndsWith('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin', $statements[0]);
        self::assertStringNotContainsString('--', $statements[0]);
        self::assertStringNotContainsString('ascii', $statements[0], 'every text column is utf8mb4');
    }

    public function testDropStatementsRemoveTheTable(): void
    {
        self::assertSame(['DROP TABLE IF EXISTS jobs'], (new MysqlSchema())->dropStatements());
    }
}
