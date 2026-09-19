<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Driver\Postgres;

use Lezhnev74\Jobs\Driver\Postgres\JobHydrator;
use Lezhnev74\Jobs\Model\TerminalKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

#[CoversClass(JobHydrator::class)]
final class JobHydratorTest extends TestCase
{
    private const ID = '0190e2a0-0000-7000-8000-00000000000a';

    /** @return array<string, mixed> */
    private static function row(): array
    {
        return [
            'id' => self::ID,
            'available_at' => '2026-09-11 10:00:00.5+00',
            'consumed_till' => null,
            'completed_at' => null,
            'discarded_at' => null,
            'last_abandoned_at' => null,
            'last_stale_ack_at' => null,
            'created_at' => '2026-09-11 09:59:00+00',
            'updated_at' => '2026-09-11 09:59:30+00',
            'lease_token' => null,
            'attempts' => 0,
            'consecutive_failures' => 0,
            'consecutive_reschedules' => 0,
            'abandoned_count' => 0,
            'stale_ack_count' => 0,
            'pool' => 'emails',
            'name' => 'billing.invoice.send',
            'dedup_key' => 'a1b2c3',
            'consumed_by' => null,
            'last_abandoned_by' => null,
            'last_stale_ack_by' => null,
            'payload' => '{"amount": 12.5, "customer": {"region": "eu"}}',
            'last_fail_reason' => null,
            'last_reschedule_reason' => null,
        ];
    }

    public function testFreshRow(): void
    {
        $job = JobHydrator::hydrate(self::row());

        self::assertSame(self::ID, $job->id->value);
        self::assertSame('billing.invoice.send', $job->name->value);
        self::assertSame('emails', $job->pool->value);
        self::assertSame(['amount' => 12.5, 'customer' => ['region' => 'eu']], $job->payload);
        self::assertSame('a1b2c3', $job->dedupKey->value);
        self::assertSame('2026-09-11 10:00:00.500000+00:00', $job->availableAt->format('Y-m-d H:i:s.uP'));
        self::assertSame('2026-09-11 09:59:00', $job->createdAt->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-11 09:59:30', $job->updatedAt->format('Y-m-d H:i:s'));
        self::assertNull($job->termination);
        self::assertNull($job->lease);
        self::assertSame([0, 0, 0, null, null], [
            $job->retry->attempts,
            $job->retry->consecutiveFailures,
            $job->retry->consecutiveReschedules,
            $job->retry->lastFailReason,
            $job->retry->lastRescheduleReason,
        ]);
        self::assertNull($job->telemetry->abandonment);
        self::assertNull($job->telemetry->staleAck);
        self::assertNull($job->telemetry->lastClaimedBy);
    }

    public function testLeasedRowCarriesTheLeaseNotLastClaimedBy(): void
    {
        $row = ['consumed_by' => 'w1', 'consumed_till' => '2026-09-11 10:05:00+00', 'lease_token' => 'x', 'attempts' => '3'] + self::row();

        $job = JobHydrator::hydrate($row);

        self::assertNotNull($job->lease);
        self::assertSame('w1', $job->lease->by);
        self::assertSame('2026-09-11 10:05:00', $job->lease->till->format('Y-m-d H:i:s'));
        self::assertSame(3, $job->retry->attempts);
        self::assertNull($job->telemetry->lastClaimedBy);
    }

    public function testSettledRowSurfacesTheLastClaimerRetryInputsAndTelemetry(): void
    {
        $row = [
            'consumed_by' => 'w2',
            'completed_at' => '2026-09-11 10:06:00+00',
            'consecutive_failures' => 2,
            'consecutive_reschedules' => 1,
            'last_fail_reason' => '{"code": "timeout"}',
            'last_reschedule_reason' => '{"code": "later"}',
            'abandoned_count' => 1,
            'last_abandoned_by' => 'w1',
            'last_abandoned_at' => '2026-09-11 10:03:00+00',
            'stale_ack_count' => 2,
            'last_stale_ack_by' => 'w1',
            'last_stale_ack_at' => '2026-09-11 10:04:00+00',
        ] + self::row();

        $job = JobHydrator::hydrate($row);

        self::assertNotNull($job->termination);
        self::assertSame(TerminalKind::Completed, $job->termination->kind);
        self::assertSame('2026-09-11 10:06:00', $job->termination->at->format('Y-m-d H:i:s'));
        self::assertSame('w2', $job->telemetry->lastClaimedBy);
        self::assertSame(['code' => 'timeout'], $job->retry->lastFailReason);
        self::assertSame(['code' => 'later'], $job->retry->lastRescheduleReason);
        self::assertSame(2, $job->retry->consecutiveFailures);
        self::assertSame(1, $job->retry->consecutiveReschedules);
        self::assertNotNull($job->telemetry->abandonment);
        self::assertSame([1, 'w1', '2026-09-11 10:03:00'], [
            $job->telemetry->abandonment->count,
            $job->telemetry->abandonment->by,
            $job->telemetry->abandonment->at->format('Y-m-d H:i:s'),
        ]);
        self::assertNotNull($job->telemetry->staleAck);
        self::assertSame(2, $job->telemetry->staleAck->count);
    }

    public function testDiscardedWinsOverCompleted(): void
    {
        $row = ['discarded_at' => '2026-09-11 10:07:00+00'] + self::row();

        $job = JobHydrator::hydrate($row);

        self::assertNotNull($job->termination);
        self::assertSame(TerminalKind::Discarded, $job->termination->kind);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function malformedRows(): iterable
    {
        yield 'missing id' => [['id' => null]];
        yield 'non-string name' => [['name' => 5]];
        yield 'missing dedup key' => [['dedup_key' => null]];
        yield 'non-numeric counter' => [['attempts' => 'many']];
        yield 'scalar json' => [['payload' => '"text"']];
        yield 'lease without worker' => [['consumed_till' => '2026-09-11 10:05:00+00']];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('malformedRows')]
    public function testMalformedRowsAreRejected(array $overrides): void
    {
        $this->expectException(UnexpectedValueException::class);

        JobHydrator::hydrate($overrides + self::row());
    }
}
