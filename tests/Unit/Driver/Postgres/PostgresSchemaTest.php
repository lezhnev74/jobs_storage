<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Driver\Postgres;

use Lezhnev74\Jobs\Driver\Postgres\PostgresSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PostgresSchema::class)]
final class PostgresSchemaTest extends TestCase
{
    public function testStatementsAreTheBundledDdlInOrder(): void
    {
        $statements = (new PostgresSchema())->statements();

        self::assertCount(4, $statements);
        self::assertStringStartsWith('CREATE TABLE jobs (', $statements[0]);
        self::assertStringStartsWith('ALTER TABLE jobs SET (', $statements[1]);
        self::assertStringStartsWith('CREATE INDEX jobs_claim_idx', $statements[2]);
        self::assertStringStartsWith('CREATE UNIQUE INDEX jobs_dedup_uk ON jobs (pool, dedup_key)', $statements[3]);
        self::assertStringNotContainsString('--', implode("\n", $statements));
    }

    public function testDropStatementsRemoveTheTable(): void
    {
        self::assertSame(['DROP TABLE IF EXISTS jobs'], (new PostgresSchema())->dropStatements());
    }
}
