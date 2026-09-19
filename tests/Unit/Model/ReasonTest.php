<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Model;

use InvalidArgumentException;
use JsonSerializable;
use Lezhnev74\Jobs\Model\Reason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Reason::class)]
final class ReasonTest extends TestCase
{
    public function testWrapsArray(): void
    {
        $reason = new Reason(['code' => 'timeout', 'ms' => 1500]);

        self::assertSame(['code' => 'timeout', 'ms' => 1500], $reason->data);
        self::assertSame(['code' => 'timeout', 'ms' => 1500], $reason->jsonSerialize());
        self::assertSame('{"code":"timeout","ms":1500}', $reason->toJson());
        self::assertSame('{"code":"timeout","ms":1500}', json_encode($reason));
    }

    public function testUnwrapsJsonSerializable(): void
    {
        $source = new class implements JsonSerializable {
            /** @return array<string, string> */
            public function jsonSerialize(): array
            {
                return ['code' => 'http/503', 'url' => 'https://x/y'];
            }
        };

        $reason = new Reason($source);

        self::assertSame(['code' => 'http/503', 'url' => 'https://x/y'], $reason->data);
        self::assertSame('{"code":"http/503","url":"https://x/y"}', $reason->toJson());
    }

    public function testRejectsJsonSerializableThatIsNotAnArray(): void
    {
        $source = new class implements JsonSerializable {
            public function jsonSerialize(): string
            {
                return 'timeout';
            }
        };

        $this->expectException(InvalidArgumentException::class);

        new Reason($source);
    }

    public function testRejectsUnencodableData(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Reason(['bytes' => "\xB1\x31"]);
    }
}
