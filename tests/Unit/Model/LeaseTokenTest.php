<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Model;

use InvalidArgumentException;
use Lezhnev74\Jobs\Model\LeaseToken;
use Lezhnev74\Jobs\Model\WorkerId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

#[CoversClass(LeaseToken::class)]
final class LeaseTokenTest extends TestCase
{
    public function testGenerateProducesUniqueUuidsCarryingTheWorker(): void
    {
        $worker = new WorkerId('w1');

        $a = LeaseToken::generate($worker);
        $b = LeaseToken::generate($worker);

        self::assertTrue(Uuid::isValid($a->value));
        self::assertSame($a->value, (string) $a);
        self::assertSame($worker, $a->worker);
        self::assertFalse($a->equals($b));
    }

    public function testFromStringNormalizesAndEqualityIgnoresWorker(): void
    {
        $token = LeaseToken::fromString('0190F0A0-1234-4ABC-8DEF-0123456789AB', new WorkerId('w1'));

        self::assertSame('0190f0a0-1234-4abc-8def-0123456789ab', $token->value);
        self::assertTrue($token->equals(LeaseToken::fromString($token->value, new WorkerId('w2'))));
    }

    public function testFromStringRejectsInvalidUuid(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LeaseToken::fromString('nope', new WorkerId('w1'));
    }
}
