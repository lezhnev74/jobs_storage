<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Model;

use InvalidArgumentException;
use Lezhnev74\Jobs\Model\JobName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(JobName::class)]
final class JobNameTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function validNames(): iterable
    {
        yield 'single segment' => ['send'];
        yield 'hierarchical' => ['billing.invoice.send'];
        yield 'digits dash underscore' => ['v2-beta_1.job'];
        yield 'max length' => [str_repeat('a', JobName::MAX_BYTES)];
    }

    #[DataProvider('validNames')]
    public function testAcceptsValidNames(string $value): void
    {
        $name = new JobName($value);

        self::assertSame($value, $name->value);
        self::assertSame($value, (string) $name);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Billing.send'];
        yield 'leading dot' => ['.send'];
        yield 'trailing dot' => ['send.'];
        yield 'double dot' => ['billing..send'];
        yield 'space' => ['billing send'];
        yield 'colon' => ['billing:send'];
        yield 'too long' => [str_repeat('a', JobName::MAX_BYTES + 1)];
        yield 'unicode' => ['счёт.send'];
    }

    #[DataProvider('invalidNames')]
    public function testRejectsInvalidNames(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JobName($value);
    }

    public function testSegmentsAndEquality(): void
    {
        $name = new JobName('billing.invoice.send');

        self::assertSame(['billing', 'invoice', 'send'], $name->segments());
        self::assertTrue($name->equals(new JobName('billing.invoice.send')));
        self::assertFalse($name->equals(new JobName('billing.invoice')));
    }
}
