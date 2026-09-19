<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Contract;

use Lezhnev74\Jobs\Model\NewJob;

/**
 * The single seam for user-owned job types: a domain concept ("send invoice 42") describes its own storage form
 * instead of leaving the mapping at every call site. Implementations are user-owned and never extend a library
 * class - composition, not inheritance.
 *
 * The returned `NewJob` must be fully formed (id, name, pool, payload, dedup key). The key defaults to the content
 * hash of pool, name and payload; override it with `NewJob::dedup()` when the business identity is narrower than the
 * payload. `toJob()` is called exactly once per push, so it must be cheap and side-effect free; it should also be
 * deterministic - minting a fresh `JobId` per call would break the idempotency that a client-generated id buys, and
 * a payload that varies per call (a timestamp, a nonce) would never deduplicate.
 *
 * There is deliberately no reverse direction (`fromJob(Job): static`): hydrating a stored job back into a user
 * type needs payload versioning and a name->class map, both application concerns with no single right answer.
 */
interface DescribesJob
{
    public function toJob(): NewJob;
}
