<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Driver\Mysql;

use Lezhnev74\Jobs\Driver\Mysql\QueueStatements;
use Lezhnev74\Jobs\Driver\Support\SettleBatch;
use Lezhnev74\Jobs\Model\Job;
use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Model\JobName;
use Lezhnev74\Jobs\Model\LeaseToken;
use Lezhnev74\Jobs\Model\NewJob;
use Lezhnev74\Jobs\Model\PoolName;
use Lezhnev74\Jobs\Model\Reason;
use Lezhnev74\Jobs\Model\Settlement;
use Lezhnev74\Jobs\Model\Ttl;
use Lezhnev74\Jobs\Model\WorkerId;
use Lezhnev74\Jobs\Query\ClaimQuery;
use Lezhnev74\Jobs\Tests\Support\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QueueStatements::class)]
final class QueueStatementsTest extends TestCase
{
    private const ID_A = '0190e2a0-0000-7000-8000-00000000000a';
    private const ID_B = '0190e2a0-0000-7000-8000-00000000000b';
    private const TOKEN = '6f1c3c1e-9a7b-4d1c-8f2e-1a2b3c4d5e6f';

    public function testPushSendsOneRowConstructorPerJob(): void
    {
        $at = Fixtures::at('2026-09-11 10:00:00');
        $a = (new NewJob(JobId::fromString(self::ID_A), new JobName('a.b'), new PoolName('p'), ['k' => 'v'], $at))->dedup('ka');
        $b = (new NewJob(JobId::fromString(self::ID_B), new JobName('c'), new PoolName('q'), [], $at->addHour()))->dedup('kb');

        $sql = QueueStatements::push($a, $b);

        self::assertSame(
            'INSERT INTO jobs (id, name, pool, payload, available_at, dedup_key) VALUES '
            . '(UUID_TO_BIN(?), ?, ?, CAST(? AS JSON), ?, ?), '
            . '(UUID_TO_BIN(?), ?, ?, CAST(? AS JSON), ?, ?)'
            . ' ON DUPLICATE KEY UPDATE id = id',
            $sql->text,
        );
        self::assertSame([
            self::ID_A, 'a.b', 'p', '{"k":"v"}', '2026-09-11 10:00:00.000000', 'ka',
            self::ID_B, 'c', 'q', '{}', '2026-09-11 11:00:00.000000', 'kb',
        ], $sql->params);
    }

    /** Filtering before the insert is what keeps a colliding push off a record a claim has locked. */
    public function testCollisionsProbesBothIdentitiesInOneNonLockingRead(): void
    {
        $at = Fixtures::at('2026-09-11 10:00:00');
        $a = (new NewJob(JobId::fromString(self::ID_A), new JobName('a.b'), new PoolName('p'), [], $at))->dedup('ka');
        $b = (new NewJob(JobId::fromString(self::ID_B), new JobName('c'), new PoolName('q'), [], $at))->dedup('kb');

        $sql = QueueStatements::collisions([$a, $b]);

        self::assertSame(
            'SELECT BIN_TO_UUID(id) AS id, pool, dedup_slot FROM jobs'
            . ' WHERE id IN (UUID_TO_BIN(?), UUID_TO_BIN(?)) OR (pool, dedup_slot) IN ((?, ?), (?, ?))',
            $sql->text,
        );
        self::assertSame([self::ID_A, self::ID_B, 'p', 'ka', 'q', 'kb'], $sql->params);
        self::assertStringNotContainsString('FOR UPDATE', $sql->text, 'the probe must never lock');
    }

    /** MySQL has no RETURNING, so what landed is derived from a PK probe either side of the insert. */
    public function testExistingIdsProbesTheBatchByPrimaryKey(): void
    {
        $sql = QueueStatements::existingIds([JobId::fromString(self::ID_A), JobId::fromString(self::ID_B)]);

        self::assertSame(
            'SELECT BIN_TO_UUID(id) AS id FROM jobs WHERE id IN (UUID_TO_BIN(?), UUID_TO_BIN(?))',
            $sql->text,
        );
        self::assertSame([self::ID_A, self::ID_B], $sql->params);
    }

    public function testPickIsTheOrderedSkipLockedLockSelectAndBindsTheLimitAsAnInteger(): void
    {
        $q = ClaimQuery::pool(new PoolName('emails'))->namePrefix('billing.')->limit(25);

        $sql = QueueStatements::pick($q);

        self::assertSame(
            'SELECT BIN_TO_UUID(id) AS id FROM jobs WHERE pool = ? AND name LIKE ?'
            . ' AND completed_at IS NULL AND discarded_at IS NULL'
            . ' AND available_at <= NOW(6) AND (consumed_till IS NULL OR consumed_till <= NOW(6))'
            . ' ORDER BY available_at LIMIT ? FOR UPDATE SKIP LOCKED',
            $sql->text,
        );
        self::assertSame(['emails', 'billing.%', 25], $sql->params);
    }

    public function testLeaseCountsAbandonmentBeforeOverwritingTheColumnsItReads(): void
    {
        $sql = QueueStatements::lease(
            [JobId::fromString(self::ID_A), JobId::fromString(self::ID_B)],
            self::token(),
            new WorkerId('w1'),
            Ttl::seconds(30),
        );

        self::assertSame(
            'UPDATE jobs SET'
            . ' abandoned_count = abandoned_count + (lease_token IS NOT NULL),'
            . ' last_abandoned_by = IF(lease_token IS NOT NULL, consumed_by, last_abandoned_by),'
            . ' last_abandoned_at = IF(lease_token IS NOT NULL, consumed_till, last_abandoned_at),'
            . ' lease_token = UUID_TO_BIN(?), consumed_by = ?, consumed_till = NOW(6) + INTERVAL ? MICROSECOND,'
            . ' attempts = attempts + 1, updated_at = NOW(6)'
            . ' WHERE id IN (UUID_TO_BIN(?), UUID_TO_BIN(?))',
            $sql->text,
        );
        self::assertSame([self::TOKEN, 'w1', 30_000_000, self::ID_A, self::ID_B], $sql->params);
    }

    public function testHydrateReadsTheLeasedRowsBackInClaimOrder(): void
    {
        $sql = QueueStatements::hydrate([JobId::fromString(self::ID_A)]);

        self::assertStringStartsWith('SELECT BIN_TO_UUID(id) AS id, available_at,', $sql->text);
        self::assertStringEndsWith(' FROM jobs WHERE id IN (UUID_TO_BIN(?)) ORDER BY available_at, id', $sql->text);
        self::assertSame([self::ID_A], $sql->params);
    }

    public function testHeartbeatIsFencedByTheTokenAndKeepsIt(): void
    {
        $sql = QueueStatements::heartbeat(self::token(), Ttl::minutes(1), [JobId::fromString(self::ID_A)]);

        self::assertSame(
            'UPDATE jobs SET consumed_till = NOW(6) + INTERVAL ? MICROSECOND, updated_at = NOW(6)'
            . ' WHERE id IN (UUID_TO_BIN(?)) AND lease_token = UUID_TO_BIN(?)',
            $sql->text,
        );
        self::assertSame([60_000_000, self::ID_A, self::TOKEN], $sql->params);
    }

    public function testAckedIdsLocksWithoutSkippingOnTheSettlePathOnly(): void
    {
        $ids = [JobId::fromString(self::ID_A)];
        $select = 'SELECT BIN_TO_UUID(id) AS id FROM jobs WHERE id IN (UUID_TO_BIN(?)) AND lease_token = UUID_TO_BIN(?)';

        self::assertSame($select . ' FOR UPDATE', QueueStatements::ackedIds($ids, self::token(), lock: true)->text);
        self::assertSame($select, QueueStatements::ackedIds($ids, self::token(), lock: false)->text);
        self::assertSame([self::ID_A, self::TOKEN], QueueStatements::ackedIds($ids, self::token(), lock: true)->params);
    }

    public function testUniformCompleteAndRelease(): void
    {
        $job = self::job(self::ID_A);
        $acked = [$job->id];
        $fence = ' WHERE id IN (UUID_TO_BIN(?)) AND lease_token = UUID_TO_BIN(?)';

        $complete = QueueStatements::settle(SettleBatch::of(Settlement::complete($job)), $acked, self::token());
        $release = QueueStatements::settle(SettleBatch::of(Settlement::release($job)), $acked, self::token());

        self::assertSame('UPDATE jobs SET completed_at = NOW(6), lease_token = NULL, consumed_till = NULL, updated_at = NOW(6)' . $fence, $complete->text);
        self::assertSame('UPDATE jobs SET lease_token = NULL, consumed_till = NULL, updated_at = NOW(6)' . $fence, $release->text);
        self::assertSame([self::ID_A, self::TOKEN], $complete->params);
        self::assertSame($complete->params, $release->params);
    }

    public function testUniformDiscardFailAndReschedule(): void
    {
        $at = Fixtures::at('2026-09-11 11:00:00');
        $why = new Reason(['code' => 'boom']);
        $jobs = [self::job(self::ID_A), self::job(self::ID_B)];
        $acked = [$jobs[0]->id, $jobs[1]->id];

        $discard = QueueStatements::settle(SettleBatch::of(Settlement::discard($jobs[0], $why), Settlement::discard($jobs[1], $why)), $acked, self::token());
        $fail = QueueStatements::settle(SettleBatch::of(Settlement::fail($jobs[0], $why, $at), Settlement::fail($jobs[1], $why, $at)), $acked, self::token());
        $reschedule = QueueStatements::settle(SettleBatch::of(Settlement::reschedule($jobs[0], $why, $at)), [$jobs[0]->id], self::token());

        self::assertStringStartsWith('UPDATE jobs SET discarded_at = NOW(6), last_fail_reason = CAST(? AS JSON), lease_token = NULL', $discard->text);
        self::assertSame(['{"code":"boom"}', self::ID_A, self::ID_B, self::TOKEN], $discard->params);

        self::assertStringStartsWith(
            'UPDATE jobs SET available_at = ?, last_fail_reason = CAST(? AS JSON),'
            . ' consecutive_failures = consecutive_failures + 1, consecutive_reschedules = 0, lease_token = NULL',
            $fail->text,
        );
        self::assertSame(['2026-09-11 11:00:00.000000', '{"code":"boom"}', self::ID_A, self::ID_B, self::TOKEN], $fail->params);

        self::assertStringStartsWith(
            'UPDATE jobs SET available_at = ?, last_reschedule_reason = CAST(? AS JSON),'
            . ' consecutive_reschedules = consecutive_reschedules + 1, consecutive_failures = 0, lease_token = NULL',
            $reschedule->text,
        );
        self::assertSame(['2026-09-11 11:00:00.000000', '{"code":"boom"}', self::ID_A, self::TOKEN], $reschedule->params);
    }

    public function testMixedBatchUsesATableValueConstructorWithPerRowInputs(): void
    {
        $at = Fixtures::at('2026-09-11 11:00:00');
        $jobs = [self::job(self::ID_A), self::job(self::ID_B)];
        $batch = SettleBatch::of(
            Settlement::fail($jobs[0], new Reason(['code' => 'x']), $at),
            Settlement::release($jobs[1]),
        );

        $sql = QueueStatements::settle($batch, [$jobs[0]->id, $jobs[1]->id], self::token());

        self::assertStringStartsWith(
            'UPDATE jobs j JOIN (VALUES ROW(UUID_TO_BIN(?), ?, CAST(? AS JSON), ?), ROW(UUID_TO_BIN(?), ?, CAST(? AS JSON), ?))'
            . ' AS s(id, outcome, reason, available_at) ON s.id = j.id SET',
            $sql->text,
        );
        self::assertStringContainsString("j.completed_at = IF(s.outcome = 'complete', NOW(6), j.completed_at)", $sql->text);
        self::assertStringContainsString(
            "j.consecutive_failures = CASE s.outcome WHEN 'fail' THEN j.consecutive_failures + 1 WHEN 'reschedule' THEN 0 ELSE j.consecutive_failures END",
            $sql->text,
        );
        self::assertStringEndsWith(
            ' j.lease_token = NULL, j.consumed_till = NULL, j.updated_at = NOW(6) WHERE j.lease_token = UUID_TO_BIN(?)',
            $sql->text,
        );
        self::assertSame([
            self::ID_A, 'fail', '{"code":"x"}', '2026-09-11 11:00:00.000000',
            self::ID_B, 'release', null, null,
            self::TOKEN,
        ], $sql->params);
    }

    public function testMixedBatchLeavesStaleRowsOutOfTheJoin(): void
    {
        $jobs = [self::job(self::ID_A), self::job(self::ID_B)];
        $batch = SettleBatch::of(Settlement::complete($jobs[0]), Settlement::release($jobs[1]));

        $sql = QueueStatements::settle($batch, [$jobs[1]->id], self::token());

        self::assertStringContainsString('JOIN (VALUES ROW(UUID_TO_BIN(?), ?, CAST(? AS JSON), ?)) AS s', $sql->text);
        self::assertSame([self::ID_B, 'release', null, null, self::TOKEN], $sql->params);
    }

    public function testStaleAckTouchesTelemetryOnly(): void
    {
        $sql = QueueStatements::staleAck([JobId::fromString(self::ID_A)], new WorkerId('w1'));

        self::assertSame(
            'UPDATE jobs SET stale_ack_count = stale_ack_count + 1, last_stale_ack_by = ?, last_stale_ack_at = NOW(6)'
            . ' WHERE id IN (UUID_TO_BIN(?))',
            $sql->text,
        );
        self::assertSame(['w1', self::ID_A], $sql->params);
    }

    private static function token(): LeaseToken
    {
        return LeaseToken::fromString(self::TOKEN, new WorkerId('w1'));
    }

    private static function job(string $id): Job
    {
        return Fixtures::job(id: JobId::fromString($id));
    }
}
