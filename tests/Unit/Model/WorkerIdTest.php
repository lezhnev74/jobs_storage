<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Model;

use InvalidArgumentException;
use Lezhnev74\Jobs\Model\WorkerId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkerId::class)]
final class WorkerIdTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function validIds(): iterable
    {
        yield 'hostname pid' => ['web-01#4321'];
        yield 'with spaces and unicode' => ['worker α 1'];
        yield 'max length' => [str_repeat('w', WorkerId::MAX_BYTES)];
    }

    #[DataProvider('validIds')]
    public function testAcceptsValidIds(string $value): void
    {
        $id = new WorkerId($value);

        self::assertSame($value, $id->value);
        self::assertSame($value, (string) $id);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIds(): iterable
    {
        yield 'empty' => [''];
        yield 'newline' => ["web\n01"];
        yield 'nul byte' => ["web\0"];
        yield 'delete char' => ["web\x7F"];
        yield 'too long' => [str_repeat('w', WorkerId::MAX_BYTES + 1)];
    }

    #[DataProvider('invalidIds')]
    public function testRejectsInvalidIds(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WorkerId($value);
    }

    public function testEquality(): void
    {
        self::assertTrue((new WorkerId('w1'))->equals(new WorkerId('w1')));
        self::assertFalse((new WorkerId('w1'))->equals(new WorkerId('w2')));
    }
}
