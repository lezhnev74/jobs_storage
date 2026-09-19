<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Driver;

use Lezhnev74\Jobs\Driver\Support\LikePattern;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LikePattern::class)]
final class LikePatternTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function prefixes(): iterable
    {
        yield 'plain' => ['billing.', 'billing.%'];
        yield 'underscore' => ['send_mail', 'send\_mail%'];
        yield 'percent' => ['100%', '100\%%'];
        yield 'backslash' => ['a\b', 'a\\\\b%'];
        yield 'empty' => ['', '%'];
    }

    #[DataProvider('prefixes')]
    public function testPrefixEscapesMetacharactersAndAppendsWildcard(string $literal, string $pattern): void
    {
        self::assertSame($pattern, LikePattern::prefix($literal));
    }

    public function testEscapeLeavesOrdinaryCharactersAlone(): void
    {
        self::assertSame('billing.invoice-send', LikePattern::escape('billing.invoice-send'));
    }
}
