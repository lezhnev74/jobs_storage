<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Model;

use InvalidArgumentException;
use Stringable;

final readonly class PoolName implements Stringable
{
    public const MAX_BYTES = 128;

    // Kept portable to external process spawners, where a pool name doubles as a pub/sub channel
    // suffix and a metrics label: no colon, no leading punctuation. Case is significant.
    private const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]*$/';

    public function __construct(public string $value)
    {
        if (\strlen($value) > self::MAX_BYTES) {
            throw new InvalidArgumentException(\sprintf('PoolName must be at most %d bytes', self::MAX_BYTES));
        }
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(\sprintf('PoolName must start with [A-Za-z0-9] and contain only [A-Za-z0-9_.-], got "%s"', $value));
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
