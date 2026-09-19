<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Model;

use InvalidArgumentException;
use JsonException;
use JsonSerializable;

/** Always an array so monitoring can aggregate by path, e.g. `reason->>'code'`. */
final readonly class Reason implements JsonSerializable
{
    /** @var array<mixed> */
    public array $data;

    /** @param array<mixed>|JsonSerializable $reason */
    public function __construct(array|JsonSerializable $reason)
    {
        $data = $reason instanceof JsonSerializable ? $reason->jsonSerialize() : $reason;
        if (!\is_array($data)) {
            throw new InvalidArgumentException(\sprintf('Reason must serialize to an array, got %s', get_debug_type($data)));
        }
        self::assertEncodable($data);
        $this->data = $data;
    }

    /** @return array<mixed> */
    public function jsonSerialize(): array
    {
        return $this->data;
    }

    public function toJson(): string
    {
        return json_encode($this->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<mixed> $data */
    private static function assertEncodable(array $data): void
    {
        try {
            json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Reason must be JSON-encodable: ' . $e->getMessage(), 0, $e);
        }
    }
}
