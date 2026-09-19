<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Model;

use InvalidArgumentException;
use Lezhnev74\Jobs\Model\PoolName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PoolName::class)]
final class PoolNameTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function validNames(): iterable
    {
        yield 'plain' => ['emails'];
        yield 'dotted' => ['emails.gold'];
        yield 'uppercase' => ['Emails'];
        yield 'mixed case' => ['fastVIP'];
        yield 'digit leading' => ['1st-pool'];
        yield 'dash underscore digits' => ['pool-1_a.v2'];
        yield 'max length' => [str_repeat('a', PoolName::MAX_BYTES)];
    }

    #[DataProvider('validNames')]
    public function testAcceptsValidNames(string $value): void
    {
        $pool = new PoolName($value);

        self::assertSame($value, $pool->value);
        self::assertSame($value, (string) $pool);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'colon variant' => ['emails:gold'];
        yield 'leading dash' => ['-emails'];
        yield 'leading underscore' => ['_defaults'];
        yield 'leading dot' => ['.emails'];
        yield 'space' => ['emails gold'];
        yield 'slash' => ['emails/gold'];
        yield 'too long' => [str_repeat('a', PoolName::MAX_BYTES + 1)];
    }

    #[DataProvider('invalidNames')]
    public function testRejectsInvalidNames(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PoolName($value);
    }

    public function testEquality(): void
    {
        self::assertTrue((new PoolName('emails'))->equals(new PoolName('emails')));
        self::assertFalse((new PoolName('emails'))->equals(new PoolName('emails.gold')));
    }

    /** Case is significant: the name is stored and compared verbatim. */
    public function testCaseIsSignificant(): void
    {
        self::assertFalse((new PoolName('emails'))->equals(new PoolName('Emails')));
    }
}
