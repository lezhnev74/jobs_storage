<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Driver\Postgres;

use Lezhnev74\Jobs\Driver\Postgres\PgLiteral;
use Lezhnev74\Jobs\Model\Ttl;
use Lezhnev74\Jobs\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PgLiteral::class)]
final class PgLiteralTest extends TestCase
{
    public function testArrayQuotesEveryElementAndEscapesQuotesAndBackslashes(): void
    {
        self::assertSame('{}', PgLiteral::array([]));
        self::assertSame('{"a","b c",NULL,"say \\"hi\\"","back\\\\slash"}', PgLiteral::array(['a', 'b c', null, 'say "hi"', 'back\\slash']));
    }

    public function testTimestamptzKeepsMicrosecondsAndOffset(): void
    {
        $at = Fixtures::at('2026-09-11 10:00:00.123456')->setTimezone('Europe/Berlin');

        self::assertSame('2026-09-11 12:00:00.123456+02:00', PgLiteral::timestamptz($at));
    }

    public function testIntervalIsWholeSeconds(): void
    {
        self::assertSame('90 seconds', PgLiteral::interval(Ttl::seconds(90)));
    }

    public function testJsonEncodesDocumentsAndEmptyAsObject(): void
    {
        self::assertSame('{}', PgLiteral::json([]));
        self::assertSame('{"a":[1,2],"b":"ü/"}', PgLiteral::json(['a' => [1, 2], 'b' => 'ü/']));
    }
}
