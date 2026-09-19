<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Contract;

use Lezhnev74\Jobs\Model\NewJob;
use Lezhnev74\Jobs\Model\PushReport;

/**
 * Write-only: a producer service holds no claim/settle capability. `NewJob::$availableAt` is always sent explicitly;
 * an omitted one defaults to the producer's clock at construction time.
 */
interface JobProducer
{
    /**
     * Idempotent on two independent identities, and never throws on either:
     *
     * - `JobId` - re-pushing an existing id is a no-op for that job forever, so a lost reply is safe to retry;
     * - `NewJob::$dedupKey` - within one pool, only one non-terminal row may hold a given key. A colliding push is
     *   dropped, never merged into the existing row; once that row completes or is discarded, the key is free again
     *   and the same work inserts as a fresh row.
     *
     * Both cases are reported through `PushReport::$deduplicated`, so a producer can tell a dropped job from a
     * stored one. A colliding batch is not aborted: the non-colliding jobs still land. A push racing a concurrent
     * settlement of the colliding row may land either way by design.
     *
     * One statement for any batch size; an empty batch is a no-op reporting nothing.
     *
     * A `DescribesJob` is converted once, at this boundary; drivers see only `NewJob`.
     */
    public function push(NewJob|DescribesJob ...$jobs): PushReport;
}
