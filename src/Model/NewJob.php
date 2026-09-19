<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Model;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use JsonException;

/** An omitted `availableAt` means "claimable as soon as pushed" - stamped from the producer clock at construction. */
final readonly class NewJob
{
    public CarbonImmutable $availableAt;

    /** Always present: an omitted key is derived from the job's content, so dedup is never silently off. */
    public DedupKey $dedupKey;

    /** @param array<string, mixed> $payload JSON-encodable document */
    public function __construct(
        public JobId $id,
        public JobName $name,
        public PoolName $pool,
        public array $payload = [],
        ?CarbonImmutable $availableAt = null,
        ?DedupKey $dedupKey = null,
    ) {
        self::assertEncodable($payload);
        $this->availableAt = $availableAt ?? CarbonImmutable::now();
        $this->dedupKey = $dedupKey ?? DedupKey::ofContent($pool, $name, $payload);
    }

    /**
     * Call-site shorthand: mints the id and accepts raw name/pool strings.
     *
     * @param array<string, mixed> $payload
     */
    public static function make(string $name, string $pool, array $payload = []): self
    {
        return new self(JobId::new(), new JobName($name), new PoolName($pool), $payload);
    }

    /** Replaces the content-derived key; the caller's string is stored verbatim, never re-hashed. */
    public function dedup(string $key): self
    {
        return new self($this->id, $this->name, $this->pool, $this->payload, $this->availableAt, DedupKey::fromString($key));
    }

    /** @param array<string, mixed> $payload */
    private static function assertEncodable(array $payload): void
    {
        try {
            json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('NewJob payload must be JSON-encodable: ' . $e->getMessage(), 0, $e);
        }
    }
}
