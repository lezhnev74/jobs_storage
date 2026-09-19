<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Driver\Mysql;

use Lezhnev74\Jobs\Driver\Mysql\MysqlLiteral;
use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MysqlLiteral::class)]
final class MysqlLiteralTest extends TestCase
{
    private const ID_A = '0190e2a0-0000-7000-8000-00000000000a';

    public function testDatetimeIsRenderedInUtcWithMicroseconds(): void
    {
        $at = Fixtures::at('2026-09-11 10:00:00.123456')->setTimezone('Europe/Berlin');

        self::assertSame('2026-09-11 10:00:00.123456', MysqlLiteral::datetime($at), 'the column has no offset, so the value must be UTC');
    }

    public function testJsonEncodesDocumentsAndEmptyAsObject(): void
    {
        self::assertSame('{}', MysqlLiteral::json([]));
        self::assertSame('{"a":[1,2],"b":"ü/"}', MysqlLiteral::json(['a' => [1, 2], 'b' => 'ü/']));
    }

    public function testIdPlaceholdersAndParamsLineUp(): void
    {
        $ids = [JobId::fromString(self::ID_A), JobId::fromString(self::ID_A)];

        self::assertSame('UUID_TO_BIN(?), UUID_TO_BIN(?)', MysqlLiteral::idPlaceholders(\count($ids)));
        self::assertSame([self::ID_A, self::ID_A], MysqlLiteral::idParams($ids));
    }
}
