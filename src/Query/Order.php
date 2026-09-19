<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Query;

/** `IdAsc` is the default - `JobId` is a UUIDv7, so id order is creation order and the PK serves it. */
enum Order
{
    case IdAsc;
    case IdDesc;
    case AvailableAtAsc;
    case AvailableAtDesc;

    /** Only an id order admits a single-column keyset cursor; `available_at` is not unique, so it would need a composite one. */
    public function isById(): bool
    {
        return $this === self::IdAsc || $this === self::IdDesc;
    }

    /** The comparison a cursor compiles to: strictly past the cursor in this order's direction. */
    public function cursorComparison(): string
    {
        return $this === self::IdDesc ? '<' : '>';
    }
}
