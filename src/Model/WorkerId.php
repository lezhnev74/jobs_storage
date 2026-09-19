<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Model;

use InvalidArgumentException;
use Stringable;

/** A label only, never the fencing token. */
final readonly class WorkerId implements Stringable
{
    public const MAX_BYTES = 128;

    public function __construct(public string $value)
    {
        if ($value === '' || \strlen($value) > self::MAX_BYTES) {
            throw new InvalidArgumentException(\sprintf('WorkerId must be 1..%d bytes', self::MAX_BYTES));
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('WorkerId must not contain control characters');
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
