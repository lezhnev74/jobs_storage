<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Model;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use Stringable;

/** Client-generated UUIDv7: makes `push` idempotent and keeps the PK index append-only. */
final readonly class JobId implements Stringable
{
    private function __construct(public string $value) {}

    public static function new(): self
    {
        return new self(Uuid::uuid7()->toString());
    }

    public static function fromString(string $value): self
    {
        if (!Uuid::isValid($value)) {
            throw new InvalidArgumentException(\sprintf('JobId must be a valid uuid, got "%s"', $value));
        }

        return new self(strtolower($value));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /** Single definition of id-set membership; `equals` suffices because the value is lowercased on construction. */
    public function isIn(self ...$ids): bool
    {
        foreach ($ids as $candidate) {
            if ($candidate->equals($this)) {
                return true;
            }
        }

        return false;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
