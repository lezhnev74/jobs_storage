<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Integration\Postgres;

use Carbon\CarbonImmutable;
use Lezhnev74\Jobs\Driver\Postgres\PostgresQueue;
use Lezhnev74\Jobs\Driver\Postgres\QueueStatements;
use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Model\JobName;
use Lezhnev74\Jobs\Model\LeaseToken;
use Lezhnev74\Jobs\Model\NewJob;
use Lezhnev74\Jobs\Model\PoolName;
use Lezhnev74\Jobs\Model\Ttl;
use Lezhnev74\Jobs\Model\WorkerId;
use Lezhnev74\Jobs\Query\ClaimQuery;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The claim statement must stream from `jobs_claim_idx` in index order and never sort. Pinned to node presence
 * rather than plan text; the table is seeded and analyzed so the planner sees real statistics instead of the
 * empty-table defaults.
 */
final class ClaimPlanTest extends PostgresTestCase
{
    private const ROWS_PER_POOL = 3000;

    protected function setUp(): void
    {
        parent::setUp();

        $queue = new PostgresQueue($this->pdo);
        foreach (['emails', 'reports', 'billing'] as $pool) {
            for ($offset = 0; $offset < self::ROWS_PER_POOL; $offset += 500) {
                $queue->push(...self::batch($pool, $offset, 500));
            }
        }
        $this->pdo->exec('ANALYZE jobs');
    }

    public function testBareClaimIsAnOrderedClaimIndexScanWithoutASort(): void
    {
        $plan = $this->explain(ClaimQuery::pool(new PoolName('emails'))->limit(50));

        self::assertClaimIndexScan($plan);
    }

    /** @return iterable<string, array{ClaimQuery}> */
    public static function narrowedClaims(): iterable
    {
        $base = ClaimQuery::pool(new PoolName('emails'))->limit(50);

        yield 'name prefix' => [$base->namePrefix('billing.')];
        yield 'payload' => [$base->payload('$.n', 1)];
    }

    /**
     * Each narrowing stays a heap filter on the same ordered scan. Stacking several very selective ones may tip the
     * planner to a bitmap scan plus a sort; the fix for that is pool naming, not indexes.
     */
    #[DataProvider('narrowedClaims')]
    public function testNarrowedClaimStaysOnTheOrderedClaimIndexScan(ClaimQuery $q): void
    {
        $plan = $this->explain($q);

        self::assertClaimIndexScan($plan);
    }

    /** @param array<string, mixed> $plan */
    private static function assertClaimIndexScan(array $plan): void
    {
        $nodes = self::nodes($plan);
        $types = array_column($nodes, 'Node Type');

        self::assertContains('Limit', $types);
        self::assertContains('LockRows', $types);
        self::assertNotContains('Sort', $types, 'the claim never sorts');
        self::assertNotContains('Seq Scan', $types);
        self::assertNotContains('Bitmap Heap Scan', $types);

        $claimScans = array_values(array_filter(
            $nodes,
            static fn(array $n): bool => ($n['Node Type'] ?? null) === 'Index Scan' && ($n['Index Name'] ?? null) === 'jobs_claim_idx',
        ));
        self::assertCount(1, $claimScans, 'exactly one ordered scan over jobs_claim_idx');
        self::assertSame('Forward', $claimScans[0]['Scan Direction'] ?? null);
    }

    /** @return array<string, mixed> the root plan node */
    private function explain(ClaimQuery $q): array
    {
        $token = LeaseToken::generate(new WorkerId('w1'));
        $sql = QueueStatements::claim($q, $token, new WorkerId('w1'), Ttl::minutes(1));
        $statement = $this->pdo->prepare('EXPLAIN (FORMAT JSON) ' . $sql->text);
        $statement->execute($sql->params);
        $json = $statement->fetchColumn();
        self::assertIsString($json);
        $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded[0]['Plan'] ?? null);

        /** @var array<string, mixed> */
        return $decoded[0]['Plan'];
    }

    /**
     * @param array<string, mixed> $node
     * @return list<array<string, mixed>>
     */
    private static function nodes(array $node): array
    {
        $flat = [$node];
        $children = $node['Plans'] ?? [];
        if (\is_array($children)) {
            foreach ($children as $child) {
                if (\is_array($child)) {
                    $flat = [...$flat, ...self::nodes($child)];
                }
            }
        }

        return $flat;
    }

    /**
     * The payload deliberately has few distinct values so the planner sees realistic selectivity, which would make
     * the default content-derived dedup keys collide; an explicit per-row key keeps all the rows.
     *
     * @return list<NewJob>
     */
    private static function batch(string $pool, int $offset, int $count): array
    {
        $base = CarbonImmutable::now()->subDays(2);
        $jobs = [];
        for ($i = 0; $i < $count; ++$i) {
            $n = $offset + $i;
            $jobs[] = (new NewJob(
                JobId::new(),
                new JobName($n % 3 === 0 ? 'billing.invoice.send' : 'mail.send'),
                new PoolName($pool),
                ['n' => $n % 5, 'tier' => $n % 2 === 0 ? 'gold' : 'silver'],
                $base->addSeconds($n),
            ))->dedup($pool . ':' . $n);
        }

        return $jobs;
    }
}
