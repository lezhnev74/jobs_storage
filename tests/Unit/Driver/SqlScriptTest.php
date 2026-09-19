<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Driver;

use Lezhnev74\Jobs\Driver\Support\SqlScript;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqlScript::class)]
final class SqlScriptTest extends TestCase
{
    public function testSplitsOnSemicolonsAndDropsComments(): void
    {
        $script = <<<'SQL'
            -- leading comment; with a semicolon
            CREATE TABLE t (
                a text, -- trailing comment
                b int /* block; comment */
            );

            /* multi
               line */
            CREATE INDEX i ON t (a);
            SQL;

        self::assertSame(
            ["CREATE TABLE t (\n    a text, \n    b int \n)", 'CREATE INDEX i ON t (a)'],
            SqlScript::statements($script),
        );
    }

    public function testQuotedTextIsOpaque(): void
    {
        $script = <<<'SQL'
            INSERT INTO t VALUES ('a;b -- not a comment', 'it''s'); SELECT "col;umn" FROM t;
            CREATE FUNCTION f() RETURNS int AS $body$ SELECT 1; -- keep $body$ LANGUAGE sql;
            SQL;

        self::assertSame(
            [
                "INSERT INTO t VALUES ('a;b -- not a comment', 'it''s')",
                'SELECT "col;umn" FROM t',
                'CREATE FUNCTION f() RETURNS int AS $body$ SELECT 1; -- keep $body$ LANGUAGE sql',
            ],
            SqlScript::statements($script),
        );
    }

    public function testEmptyStatementsAreDropped(): void
    {
        self::assertSame([], SqlScript::statements("  ;\n-- only a comment\n;"));
        self::assertSame(['DROP TABLE IF EXISTS jobs'], SqlScript::statements('DROP TABLE IF EXISTS jobs'));
    }
}
