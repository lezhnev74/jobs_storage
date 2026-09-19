<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Model;

use InvalidArgumentException;
use Lezhnev74\Jobs\Model\DedupKey;
use Lezhnev74\Jobs\Model\JobName;
use Lezhnev74\Jobs\Model\PoolName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DedupKey::class)]
final class DedupKeyTest extends TestCase
{
    public function testExplicitKeyIsStoredVerbatim(): void
    {
        $key = DedupKey::fromString('invoice:2026-09:acme');

        self::assertSame('invoice:2026-09:acme', $key->value);
        self::assertSame('invoice:2026-09:acme', (string) $key);
    }

    public function testRejectsEmptyKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DedupKey::fromString('');
    }

    public function testRejectsOversizedKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DedupKey::fromString(str_repeat('a', DedupKey::MAX_BYTES + 1));
    }

    public function testAcceptsKeyAtTheSizeLimit(): void
    {
        $key = DedupKey::fromString(str_repeat('a', DedupKey::MAX_BYTES));

        self::assertSame(DedupKey::MAX_BYTES, \strlen($key->value));
    }

    public function testTheSizeLimitIsMeasuredInBytesNotCharacters(): void
    {
        self::assertSame(256, \strlen(DedupKey::fromString(str_repeat('é', 128))->value));

        $this->expectException(InvalidArgumentException::class);

        DedupKey::fromString(str_repeat('é', 129));
    }

    public function testAcceptsAnyValidUtf8(): void
    {
        self::assertSame('invoice:Müller:😀', DedupKey::fromString('invoice:Müller:😀')->value);
    }

    public function testRejectsInvalidUtf8(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DedupKey::fromString("k\xff\xfe");
    }

    /** MySQL's PAD SPACE collations would make `k` and `k ` collide where Postgres keeps them apart. */
    public function testSurroundingWhitespaceIsTrimmed(): void
    {
        self::assertSame('k', DedupKey::fromString(" \t k \n\u{00A0}")->value);
        self::assertSame('a b', DedupKey::fromString('a b')->value, 'inner whitespace is part of the key');
    }

    public function testRejectsWhitespaceOnlyKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DedupKey::fromString("  \t ");
    }

    public function testContentKeyIsASha256Hex(): void
    {
        $key = self::content(['a' => 1]);

        self::assertSame(64, \strlen($key->value));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key->value);
    }

    public function testIdenticalContentProducesTheIdenticalKey(): void
    {
        self::assertTrue(self::content(['a' => 1, 'b' => [2, 3]])->equals(self::content(['a' => 1, 'b' => [2, 3]])));
    }

    public function testTopLevelKeyOrderDoesNotChangeTheKey(): void
    {
        self::assertTrue(self::content(['a' => 1, 'b' => 2])->equals(self::content(['b' => 2, 'a' => 1])));
    }

    public function testNestedObjectsAreSortedAtEveryDepth(): void
    {
        $one = self::content(['o' => ['x' => ['p' => 1, 'q' => 2], 'y' => 3]]);
        $two = self::content(['o' => ['y' => 3, 'x' => ['q' => 2, 'p' => 1]]]);

        self::assertTrue($one->equals($two));
    }

    public function testObjectsNestedInsideListsAreSortedToo(): void
    {
        $one = self::content(['items' => [['a' => 1, 'b' => 2]]]);
        $two = self::content(['items' => [['b' => 2, 'a' => 1]]]);

        self::assertTrue($one->equals($two));
    }

    public function testListOrderIsPartOfTheKey(): void
    {
        self::assertFalse(self::content(['l' => [1, 2]])->equals(self::content(['l' => [2, 1]])));
    }

    public function testPoolIsPartOfTheKey(): void
    {
        $one = DedupKey::ofContent(new PoolName('emails'), new JobName('send'), ['a' => 1]);
        $two = DedupKey::ofContent(new PoolName('sms'), new JobName('send'), ['a' => 1]);

        self::assertFalse($one->equals($two));
    }

    public function testNameIsPartOfTheKey(): void
    {
        $one = DedupKey::ofContent(new PoolName('emails'), new JobName('send'), ['a' => 1]);
        $two = DedupKey::ofContent(new PoolName('emails'), new JobName('resend'), ['a' => 1]);

        self::assertFalse($one->equals($two));
    }

    public function testPayloadIsPartOfTheKey(): void
    {
        self::assertFalse(self::content(['a' => 1])->equals(self::content(['a' => 2])));
    }

    /** Pool and name are separate hash inputs, so shifting a character across the boundary must change the key. */
    public function testPoolAndNameAreNotConcatenatedAmbiguously(): void
    {
        $one = DedupKey::ofContent(new PoolName('ab'), new JobName('c'), []);
        $two = DedupKey::ofContent(new PoolName('a'), new JobName('bc'), []);

        self::assertFalse($one->equals($two));
    }

    public function testFloatsAndUnicodeRoundTripStably(): void
    {
        $payload = ['pi' => 3.5, 'label' => 'Ünïcødé ✓', 'path' => 'a/b/c'];

        self::assertTrue(self::content($payload)->equals(self::content($payload)));
    }

    public function testScalarTypesAreDistinguished(): void
    {
        self::assertFalse(self::content(['a' => 1])->equals(self::content(['a' => '1'])));
        self::assertFalse(self::content(['a' => null])->equals(self::content(['a' => false])));
    }

    public function testRejectsUnencodableContent(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::content(['bad' => "\xB1\x31"]);
    }

    /** @param array<string, mixed> $payload */
    private static function content(array $payload): DedupKey
    {
        return DedupKey::ofContent(new PoolName('emails'), new JobName('billing.invoice.send'), $payload);
    }
}
