<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Model;

use InvalidArgumentException;
use JsonException;
use Stringable;

/**
 * Business-level job identity, orthogonal to `JobId`: `JobId` answers "did my push land?", a `DedupKey` answers
 * "is this work already queued?". Uniqueness is enforced per pool over non-terminal rows only, so finished work
 * can be queued again.
 *
 * The default-key byte form is a compatibility surface: changing the canonicalization changes every default key
 * and silently re-enqueues in-flight work. See `dev_docs/dedup.md`.
 */
final readonly class DedupKey implements Stringable
{
    public const MAX_BYTES = 256;

    /** Stable byte form for hashing: slashes and unicode stay unescaped so the encoder's escaping style is not part of the identity. */
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private function __construct(public string $value) {}

    /**
     * Caller-supplied key, stored as given (never re-hashed) so it stays greppable in the database. Any valid UTF-8,
     * capped in bytes so it fits `VARCHAR(256)` under any charset. Surrounding whitespace is trimmed: MySQL's PAD
     * SPACE collations would otherwise make `k` and `k ` collide where Postgres keeps them apart.
     */
    public static function fromString(string $value): self
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('DedupKey must be valid UTF-8');
        }
        $value = (string) preg_replace('/^\s+|\s+$/u', '', $value);
        if ($value === '') {
            throw new InvalidArgumentException('DedupKey must not be empty');
        }
        if (\strlen($value) > self::MAX_BYTES) {
            throw new InvalidArgumentException(\sprintf('DedupKey must be at most %d bytes', self::MAX_BYTES));
        }

        return new self($value);
    }

    /**
     * Default key: sha256 over canonical JSON of `[pool, name, payload]`. `availableAt` is deliberately excluded -
     * scheduling time is not identity, and including it would defeat dedup for the common "same work, maybe sooner"
     * push.
     *
     * @param array<string, mixed> $payload
     */
    public static function ofContent(PoolName $pool, JobName $name, array $payload): self
    {
        try {
            $json = json_encode([$pool->value, $name->value, self::canonicalize($payload)], self::JSON_FLAGS);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('DedupKey content must be JSON-encodable: ' . $e->getMessage(), 0, $e);
        }

        return new self(hash('sha256', $json));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Recursive key-sort of associative arrays so PHP insertion order never changes the hash; lists keep their order
     * because position is meaningful in a list.
     */
    private static function canonicalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $canonical = array_map(self::canonicalize(...), $value);

        if (!$isList) {
            ksort($canonical, SORT_STRING);
        }

        return $canonical;
    }
}
