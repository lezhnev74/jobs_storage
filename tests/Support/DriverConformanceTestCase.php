<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Support;

use Carbon\CarbonImmutable;
use Lezhnev74\Jobs\Contract\JobMonitor;
use Lezhnev74\Jobs\Contract\JobQueue;
use Lezhnev74\Jobs\Driver\Contract\Driver;
use Lezhnev74\Jobs\Model\ClaimedJobs;
use Lezhnev74\Jobs\Model\DedupKey;
use Lezhnev74\Jobs\Model\Job;
use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Model\JobName;
use Lezhnev74\Jobs\Model\NewJob;
use Lezhnev74\Jobs\Model\PoolName;
use Lezhnev74\Jobs\Model\Ttl;
use Lezhnev74\Jobs\Model\WorkerId;
use Lezhnev74\Jobs\Query\ClaimQuery;
use Lezhnev74\Jobs\Query\JobQuery;
use Lezhnev74\Jobs\Tests\Support\Conformance\ClaimConformance;
use Lezhnev74\Jobs\Tests\Support\Conformance\ConcurrencyConformance;
use Lezhnev74\Jobs\Tests\Support\Conformance\DedupConformance;
use Lezhnev74\Jobs\Tests\Support\Conformance\DescribesJobConformance;
use Lezhnev74\Jobs\Tests\Support\Conformance\FencingConformance;
use Lezhnev74\Jobs\Tests\Support\Conformance\HeartbeatConformance;
use Lezhnev74\Jobs\Tests\Support\Conformance\JanitorConformance;
use Lezhnev74\Jobs\Tests\Support\Conformance\LookupConformance;
use Lezhnev74\Jobs\Tests\Support\Conformance\PushConformance;
use Lezhnev74\Jobs\Tests\Support\Conformance\ReaderConformance;
use Lezhnev74\Jobs\Tests\Support\Conformance\SettleConformance;
use PHPUnit\Framework\TestCase;

/**
 * The suite drops and rebuilds the schema before every test, so point it at a throwaway database.
 *
 * Time comparisons run on the database clock, never PHP's, so lease-expiry tests wait out `minTtl()` for real.
 * Timestamps the tests send as data (`available_at`) come from the PHP clock with a wide margin, so a modest skew
 * between the two clocks is harmless.
 */
abstract class DriverConformanceTestCase extends TestCase
{
    use PushConformance;
    use DedupConformance;
    use DescribesJobConformance;
    use ClaimConformance;
    use HeartbeatConformance;
    use SettleConformance;
    use FencingConformance;
    use ReaderConformance;
    use LookupConformance;
    use JanitorConformance;
    use ConcurrencyConformance;

    /** Static, so keys stay distinct across tests even though each test rebuilds the schema. */
    private static int $dedupNonce = 0;

    private ?Driver $driver = null;

    /**
     * Must open a new connection every time rather than return a cached one: the concurrency tests call this again
     * to get a second, independent connection.
     */
    abstract protected function connect(): Driver;

    abstract protected function execute(string $statement): void;

    /**
     * Shortest lease the expiry tests may rely on; each such test sleeps this long for real. Raise it if the
     * backend's clock resolution needs a longer floor.
     */
    protected function minTtl(): Ttl
    {
        return Ttl::seconds(1);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $schema = $this->driver()->schema();
        foreach ([...$schema->dropStatements(), ...$schema->statements()] as $statement) {
            $this->execute($statement);
        }
    }

    final protected function driver(): Driver
    {
        return $this->driver ??= $this->connect();
    }

    final protected function queue(): JobQueue
    {
        return $this->driver()->queue();
    }

    final protected function monitor(): JobMonitor
    {
        return $this->driver()->monitor();
    }

    /**
     * The dedup key defaults to a per-call nonce, not to the content hash: most tests push several jobs of
     * identical content on purpose and must not be deduplicated. Dedup behaviour is asserted by `DedupConformance`,
     * which passes the key explicitly.
     *
     * @param array<string, mixed> $payload
     */
    final protected function newJob(
        ?JobId $id = null,
        string $name = 'billing.invoice.send',
        string $pool = 'emails',
        ?CarbonImmutable $availableAt = null,
        array $payload = ['customer' => ['region' => 'eu'], 'amount' => 12.5],
        ?string $dedupKey = null,
    ): NewJob {
        return new NewJob(
            id: $id ?? JobId::new(),
            name: new JobName($name),
            pool: new PoolName($pool),
            payload: $payload,
            availableAt: $availableAt,
            dedupKey: DedupKey::fromString($dedupKey ?? 'test-' . ++self::$dedupNonce),
        );
    }

    /**
     * `available_at` ascends in push order, so tests can assert oldest-first ordering.
     *
     * @return list<JobId>
     */
    final protected function pushClaimable(int $count, string $pool = 'emails', string $name = 'billing.invoice.send'): array
    {
        $jobs = [];
        for ($i = 0; $i < $count; ++$i) {
            $jobs[] = $this->newJob(name: $name, pool: $pool, availableAt: CarbonImmutable::now()->subHour()->addSeconds($i));
        }
        $this->queue()->push(...$jobs);

        return array_map(static fn(NewJob $job): JobId => $job->id, $jobs);
    }

    final protected function claim(int $limit = 10, string $worker = 'w1', ?Ttl $ttl = null, string $pool = 'emails'): ClaimedJobs
    {
        return $this->queue()->claim(ClaimQuery::pool(new PoolName($pool))->limit($limit), new WorkerId($worker), $ttl ?? Ttl::minutes(5));
    }

    /** Sleeps out a lease of `minTtl()` taken just before, with a margin for clock skew. */
    final protected function waitOutLease(): void
    {
        usleep($this->minTtl()->seconds * 1_000_000 + 300_000);
    }

    final protected function getJob(JobId $id): Job
    {
        $job = $this->monitor()->get($id);
        self::assertNotNull($job, \sprintf('job %s must exist', $id));

        return $job;
    }

    final protected function poolQuery(string $pool = 'emails'): JobQuery
    {
        return JobQuery::from(ClaimQuery::pool(new PoolName($pool)));
    }

    /**
     * @param iterable<Job> $jobs
     * @return list<string>
     */
    final protected static function idsOf(iterable $jobs): array
    {
        $ids = [];
        foreach ($jobs as $job) {
            $ids[] = $job->id->value;
        }

        return $ids;
    }

    /**
     * @param list<JobId> $ids
     * @param iterable<Job> $jobs
     */
    final protected static function assertSameIds(array $ids, iterable $jobs, string $message = ''): void
    {
        self::assertSame(array_map(static fn(JobId $id): string => $id->value, $ids), self::idsOf($jobs), $message);
    }

    /** @param list<JobId> $ids */
    final protected static function assertSameIdSet(array $ids, ClaimedJobs $claimed, string $message = ''): void
    {
        $expected = array_map(static fn(JobId $id): string => $id->value, $ids);
        $actual = self::idsOf($claimed->jobs);
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual, $message);
    }

    /**
     * Stores may reorder object keys (jsonb sorts them), so compare structure, not order.
     *
     * @param array<mixed> $expected
     * @param array<mixed> $actual
     */
    final protected static function assertSameDocument(array $expected, array $actual): void
    {
        self::assertSame(self::sorted($expected), self::sorted($actual));
    }

    final protected static function assertUnleased(Job $job, string $lastClaimedBy): void
    {
        self::assertNull($job->lease, 'settlement clears the lease');
        self::assertSame($lastClaimedBy, $job->telemetry->lastClaimedBy, 'consumed_by survives settlement');
    }

    final protected static function assertFreshRetry(Job $job): void
    {
        self::assertSame([0, 0, 0, null, null], self::retryTuple($job));
    }

    final protected static function assertNoTelemetry(Job $job): void
    {
        self::assertNull($job->telemetry->abandonment);
        self::assertNull($job->telemetry->staleAck);
        self::assertNull($job->telemetry->lastClaimedBy);
    }

    /** @return array{int, int, int, array<mixed>|null, array<mixed>|null} attempts, failures, reschedules, reasons */
    final protected static function retryTuple(Job $job): array
    {
        $retry = $job->retry;

        return [$retry->attempts, $retry->consecutiveFailures, $retry->consecutiveReschedules, $retry->lastFailReason, $retry->lastRescheduleReason];
    }

    /**
     * @param array<mixed> $document
     * @return array<mixed>
     */
    private static function sorted(array $document): array
    {
        ksort($document);

        return array_map(static fn(mixed $v): mixed => \is_array($v) ? self::sorted($v) : $v, $document);
    }
}
