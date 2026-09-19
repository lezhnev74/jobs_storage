<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Model;

use InvalidArgumentException;
use Lezhnev74\Jobs\Model\JobId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

#[CoversClass(JobId::class)]
final class JobIdTest extends TestCase
{
    public function testNewGeneratesUuidV7(): void
    {
        $id = JobId::new();

        self::assertTrue(Uuid::isValid($id->value));
        self::assertSame('7', $id->value[14]);
        self::assertSame($id->value, (string) $id);
    }

    public function testNewIdsAreUniqueAndSortable(): void
    {
        $a = JobId::new();
        $b = JobId::new();

        self::assertFalse($a->equals($b));
        self::assertLessThanOrEqual($b->value, $a->value);
    }

    public function testFromStringNormalizesCase(): void
    {
        $id = JobId::fromString('0190F0A0-1234-7ABC-8DEF-0123456789AB');

        self::assertSame('0190f0a0-1234-7abc-8def-0123456789ab', $id->value);
        self::assertTrue($id->equals(JobId::fromString('0190f0a0-1234-7abc-8def-0123456789ab')));
    }

    public function testFromStringRejectsInvalidUuid(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JobId::fromString('not-a-uuid');
    }

    public function testIsInMatchesByValueNotIdentity(): void
    {
        $a = JobId::new();
        $b = JobId::new();

        self::assertTrue($a->isIn($b, JobId::fromString($a->value)));
        self::assertFalse($a->isIn($b));
        self::assertFalse($a->isIn());
    }
}
