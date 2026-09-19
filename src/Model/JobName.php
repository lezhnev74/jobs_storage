<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Model;

use InvalidArgumentException;
use Stringable;

final readonly class JobName implements Stringable
{
    public const MAX_BYTES = 128;

    private const PATTERN = '/^[a-z0-9_-]+(\.[a-z0-9_-]+)*$/';

    public function __construct(public string $value)
    {
        if (\strlen($value) > self::MAX_BYTES) {
            throw new InvalidArgumentException(\sprintf('JobName must be at most %d bytes', self::MAX_BYTES));
        }
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(\sprintf('JobName must be dot-separated [a-z0-9_-] segments, got "%s"', $value));
        }
    }

    /** @return list<string> */
    public function segments(): array
    {
        return explode('.', $this->value);
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
