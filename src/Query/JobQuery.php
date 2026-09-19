<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Query;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Lezhnev74\Jobs\Model\DedupKey;
use Lezhnev74\Jobs\Model\JobId;

/**
 * Only the filter criteria of the wrapped query are carried over (pool, name prefix, payload);
 * `ClaimQuery::$limit` is the claim batch size and does not apply here - the cold-path limit is `$limit`, `null`
 * meaning unbounded. Immutable: every wither returns a new instance.
 */
final readonly class JobQuery
{
    /**
     * @param list<State> $states empty = any state
     * @param ?CarbonImmutable $availableBefore `available_at <= $availableBefore`
     * @param ?CarbonImmutable $availableAfter `available_at > $availableAfter`
     * @param list<DedupKey> $dedupKeys empty = any key
     * @param ?JobId $after keyset cursor, exclusive; only valid under an id order
     */
    private function __construct(
        public ClaimQuery $claim,
        public array $states,
        public ?CarbonImmutable $availableBefore,
        public ?CarbonImmutable $availableAfter,
        public array $dedupKeys,
        public Order $orderBy,
        public ?int $limit,
        public ?JobId $after = null,
    ) {
        if ($availableBefore !== null && $availableAfter !== null && $availableAfter >= $availableBefore) {
            throw new InvalidArgumentException('JobQuery availableAfter must precede availableBefore');
        }
        // The cursor is a position in the sort, so it is only meaningful when the sort is the id itself.
        if ($after !== null && !$orderBy->isById()) {
            throw new InvalidArgumentException('JobQuery after() requires an id order, got ' . $orderBy->name);
        }
    }

    public static function from(ClaimQuery $claim): self
    {
        return new self($claim, [], null, null, [], Order::IdAsc, null, null);
    }

    public function states(State ...$states): self
    {
        $merged = $this->states;
        foreach ($states as $state) {
            if (!\in_array($state, $merged, true)) {
                $merged[] = $state;
            }
        }

        return new self($this->claim, $merged, $this->availableBefore, $this->availableAfter, $this->dedupKeys, $this->orderBy, $this->limit, $this->after);
    }

    public function availableBefore(CarbonImmutable $cutoff): self
    {
        return new self($this->claim, $this->states, $cutoff, $this->availableAfter, $this->dedupKeys, $this->orderBy, $this->limit, $this->after);
    }

    public function availableAfter(CarbonImmutable $cutoff): self
    {
        return new self($this->claim, $this->states, $this->availableBefore, $cutoff, $this->dedupKeys, $this->orderBy, $this->limit, $this->after);
    }

    /**
     * Filters on the business identity across *all* states, so the history of every run of one key is readable. The
     * dedup index covers live rows only, so this is a heap filter like `payload` - acceptable on the cold path.
     * Keys accumulate, so repeated calls widen the `IN` set; a duplicate key is added once.
     */
    public function dedupKeys(DedupKey ...$keys): self
    {
        $merged = $this->dedupKeys;
        foreach ($keys as $key) {
            foreach ($merged as $known) {
                if ($known->equals($key)) {
                    continue 2;
                }
            }
            $merged[] = $key;
        }

        return new self($this->claim, $this->states, $this->availableBefore, $this->availableAfter, $merged, $this->orderBy, $this->limit, $this->after);
    }

    public function orderBy(Order $order): self
    {
        return new self($this->claim, $this->states, $this->availableBefore, $this->availableAfter, $this->dedupKeys, $order, $this->limit, $this->after);
    }

    public function limit(int $limit): self
    {
        if ($limit < 1) {
            throw new InvalidArgumentException(\sprintf('JobQuery limit must be at least 1, got %d', $limit));
        }

        return new self($this->claim, $this->states, $this->availableBefore, $this->availableAfter, $this->dedupKeys, $this->orderBy, $limit, $this->after);
    }

    /**
     * Keyset cursor: reads resume strictly past `$cursor` in the current id order. Ids are UUIDv7, so this is both
     * the primary key and creation order - the page is served by an index range scan, unlike an OFFSET that would
     * re-scan every skipped row. Pass the last id of the previous page to walk a large result.
     *
     * Position is stable because ids never change, but a page's *contents* are not a snapshot: a row read on page 1
     * may have settled by the time page 9 is read, and a row inserted mid-walk appears if it sorts after the cursor.
     *
     * `count()` and `aggregate()` ignore it, as they ignore `orderBy` and `limit`.
     */
    public function after(JobId $cursor): self
    {
        return new self($this->claim, $this->states, $this->availableBefore, $this->availableAfter, $this->dedupKeys, $this->orderBy, $this->limit, $cursor);
    }
}
