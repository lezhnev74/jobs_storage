<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Support;

use Lezhnev74\Jobs\Model\AckReport;
use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Model\Outcome;
use Lezhnev74\Jobs\Model\Settlement;

/**
 * Classifies a `settle()` batch for statement dispatch: a uniform batch compiles to a single-SET template with
 * scalar reason / availableAt parameters, anything else to the bulk form.
 */
final readonly class SettleBatch
{
    /** @param list<Settlement> $settlements */
    private function __construct(
        public array $settlements,
        public ?Outcome $outcome,
    ) {}

    public static function of(Settlement ...$batch): self
    {
        $settlements = array_values($batch);

        return new self($settlements, self::uniformOutcome($settlements));
    }

    public function isEmpty(): bool
    {
        return $this->settlements === [];
    }

    /**
     * Requires identical inputs (reason, availableAt) as well as one shared outcome, so a single template with
     * scalar parameters covers the batch. An empty batch is not uniform: there is nothing to dispatch.
     */
    public function isUniform(): bool
    {
        return $this->outcome !== null;
    }

    /** @return list<JobId> */
    public function ids(): array
    {
        return array_map(static fn(Settlement $s): JobId => $s->job->id, $this->settlements);
    }

    /**
     * Stale ids whose requested outcome terminates or requeues - the ones `stale_ack_*` records. A stale `release`
     * is reported but never counted.
     *
     * @return list<JobId>
     */
    public function staleToCount(AckReport $report): array
    {
        $counted = [];
        foreach ($this->settlements as $settlement) {
            if ($settlement->outcome !== Outcome::Release && $report->isStale($settlement->job->id)) {
                $counted[] = $settlement->job->id;
            }
        }

        return $counted;
    }

    /** @param list<Settlement> $settlements */
    private static function uniformOutcome(array $settlements): ?Outcome
    {
        if ($settlements === []) {
            return null;
        }

        $first = $settlements[0];
        foreach ($settlements as $settlement) {
            if (!self::sameInputs($first, $settlement)) {
                return null;
            }
        }

        return $first->outcome;
    }

    private static function sameInputs(Settlement $a, Settlement $b): bool
    {
        return $a->outcome === $b->outcome
            && $a->reason?->toJson() === $b->reason?->toJson()
            && self::sameInstant($a, $b);
    }

    private static function sameInstant(Settlement $a, Settlement $b): bool
    {
        if ($a->availableAt === null || $b->availableAt === null) {
            return $a->availableAt === $b->availableAt;
        }

        return $a->availableAt->equalTo($b->availableAt);
    }
}
