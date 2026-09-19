<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Query;

use InvalidArgumentException;
use Lezhnev74\Jobs\Model\JobName;
use Lezhnev74\Jobs\Model\PoolName;

/**
 * Criteria `claim` would not honor (states, lease conditions, ordering) have no representation here. Immutable:
 * every wither returns a new instance.
 *
 * `payload()` filters use the driver's containment mechanism (`payload @> {...}` on Postgres), so paths are plain
 * member chains from the document root (`$.customer.region`).
 */
final readonly class ClaimQuery
{
    public const DEFAULT_LIMIT = 1;
    public const MAX_LIMIT = 10_000;

    private const PREFIX_PATTERN = '/^[a-z0-9_.-]+$/';
    private const PATH_PATTERN = '/^\$(\.[A-Za-z0-9_-]+)+$/';

    /** @param array<string, mixed> $payload JSON path => exact value, all must match */
    private function __construct(
        public PoolName $pool,
        public ?string $namePrefix,
        public array $payload,
        public int $limit,
    ) {}

    public static function pool(PoolName $pool): self
    {
        return new self($pool, null, [], self::DEFAULT_LIMIT);
    }

    public function namePrefix(string $prefix): self
    {
        self::assertPrefix($prefix);

        return new self($this->pool, $prefix, $this->payload, $this->limit);
    }

    public function payload(string $path, mixed $value): self
    {
        if (preg_match(self::PATH_PATTERN, $path) !== 1) {
            throw new InvalidArgumentException(\sprintf('ClaimQuery payload path must be a `$.member.member` chain, got "%s"', $path));
        }

        return new self($this->pool, $this->namePrefix, [...$this->payload, $path => $value], $this->limit);
    }

    public function limit(int $limit): self
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException(\sprintf('ClaimQuery limit must be within 1..%d, got %d', self::MAX_LIMIT, $limit));
        }

        return new self($this->pool, $this->namePrefix, $this->payload, $limit);
    }

    private static function assertPrefix(string $prefix): void
    {
        if ($prefix === '' || \strlen($prefix) > JobName::MAX_BYTES) {
            throw new InvalidArgumentException(\sprintf('ClaimQuery name prefix must be 1..%d bytes', JobName::MAX_BYTES));
        }
        if (preg_match(self::PREFIX_PATTERN, $prefix) !== 1) {
            throw new InvalidArgumentException(\sprintf('ClaimQuery name prefix must consist of [a-z0-9_.-], got "%s"', $prefix));
        }
    }
}
