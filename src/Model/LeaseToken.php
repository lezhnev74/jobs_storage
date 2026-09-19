<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Model;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use Stringable;

/**
 * The database stores only the uuid; the PHP value also carries the worker id so a fenced-out ack can be
 * attributed (`last_stale_ack_by`).
 */
final readonly class LeaseToken implements Stringable
{
    private function __construct(public string $value, public WorkerId $worker) {}

    public static function generate(WorkerId $worker): self
    {
        return new self(Uuid::uuid4()->toString(), $worker);
    }

    public static function fromString(string $value, WorkerId $worker): self
    {
        if (!Uuid::isValid($value)) {
            throw new InvalidArgumentException(\sprintf('LeaseToken must be a valid uuid, got "%s"', $value));
        }

        return new self(strtolower($value), $worker);
    }

    /** Fencing compares the uuid only; the worker label is not part of the identity. */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
