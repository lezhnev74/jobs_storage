<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Unit\Driver\Postgres;

use Lezhnev74\Jobs\Driver\Postgres\QueueStatements;
use Lezhnev74\Jobs\Driver\Support\SettleBatch;
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

    public function testPushSendsOneArrayPerColumn(): void
    {
        $at = Fixtures::at('2026-09-11 10:00:00');
        $a = (new NewJob(JobId::fromString(self::ID_A), new JobName('a.b'), new PoolName('p'), ['k' => 'v'], $at))->dedup('ka');
        $b = (new NewJob(JobId::fromString(self::ID_B), new JobName('c'), new PoolName('q'), [], $at->addHour()))->dedup('kb');

        $sql = QueueStatements::push($a, $b);

        self::assertStringContainsString('INSERT INTO jobs (id, name, pool, payload, available_at, dedup_key)', $sql->text);
        self::assertStringContainsString('SELECT n.id, n.name, n.pool, n.payload, n.available_at, n.dedup_key', $sql->text);
        self::assertStringContainsString('unnest(?::uuid[], ?::text[], ?::text[], ?::jsonb[], ?::timestamptz[], ?::text[])', $sql->text);
        self::assertStringEndsWith('ON CONFLICT DO NOTHING RETURNING id', $sql->text, 'untargeted, so both unique constraints are covered by one clause');
        self::assertSame([
            '{"' . self::ID_A . '","' . self::ID_B . '"}',
            '{"a.b","c"}',
            '{"p","q"}',
            '{"{\\"k\\":\\"v\\"}","{}"}',
            '{"2026-09-11 10:00:00.000000+00:00","2026-09-11 11:00:00.000000+00:00"}',
            '{"ka","kb"}',
        ], $sql->params);
    }

    public function testClaimIsTheOrderedSkipLockedUpdateWithInlineAbandonmentCounting(): void
    {
        $q = ClaimQuery::pool(new PoolName('emails'))->namePrefix('billing.')->limit(25);

        $sql = QueueStatements::claim($q, self::token(), new WorkerId('w1'), Ttl::seconds(30));

        self::assertStringStartsWith('UPDATE jobs j SET lease_token = ?, consumed_by = ?, consumed_till = now() + ?::interval,', $sql->text);
        self::assertStringContainsString('attempts = j.attempts + 1', $sql->text);
        self::assertStringContainsString('abandoned_count = j.abandoned_count + (j.lease_token IS NOT NULL)::int', $sql->text);
        self::assertStringContainsString('last_abandoned_by = CASE WHEN j.lease_token IS NOT NULL THEN j.consumed_by ELSE j.last_abandoned_by END', $sql->text);
        self::assertStringContainsString('last_abandoned_at = CASE WHEN j.lease_token IS NOT NULL THEN j.consumed_till ELSE j.last_abandoned_at END', $sql->text);
        self::assertStringContainsString(
            'FROM (SELECT id FROM jobs WHERE pool = ? AND name LIKE ? AND completed_at IS NULL AND discarded_at IS NULL'
            . ' AND available_at <= now() AND (consumed_till IS NULL OR consumed_till <= now())'
            . ' ORDER BY available_at LIMIT ? FOR UPDATE SKIP LOCKED) picked WHERE j.id = picked.id RETURNING j.*',
            $sql->text,
        );
        self::assertSame([self::TOKEN, 'w1', '30 seconds', 'emails', 'billing.%', '25'], $sql->params);
    }

    public function testHeartbeatIsFencedByTheToken(): void
    {
        $sql = QueueStatements::heartbeat(self::token(), Ttl::minutes(1), [JobId::fromString(self::ID_A)]);

        self::assertSame(
            'UPDATE jobs SET consumed_till = now() + ?::interval, updated_at = now()'
            . ' WHERE id = ANY(?::uuid[]) AND lease_token = ? RETURNING id',
            $sql->text,
        );
        self::assertSame(['60 seconds', '{"' . self::ID_A . '"}', self::TOKEN], $sql->params);
    }

    public function testUniformCompleteAndRelease(): void
    {
        $job = self::job(self::ID_A);
        $fence = ' WHERE id = ANY(?::uuid[]) AND lease_token = ? RETURNING id';

        $complete = QueueStatements::settle(SettleBatch::of(Settlement::complete($job)), self::token());
        $release = QueueStatements::settle(SettleBatch::of(Settlement::release($job)), self::token());

        self::assertSame('UPDATE jobs SET completed_at = now(), lease_token = NULL, consumed_till = NULL, updated_at = now()' . $fence, $complete->text);
        self::assertSame('UPDATE jobs SET lease_token = NULL, consumed_till = NULL, updated_at = now()' . $fence, $release->text);
        self::assertSame(['{"' . self::ID_A . '"}', self::TOKEN], $complete->params);
        self::assertSame($complete->params, $release->params);
    }

    public function testUniformDiscardFailAndReschedule(): void
    {
        $at = Fixtures::at('2026-09-11 11:00:00');
        $why = new Reason(['code' => 'boom']);
        $jobs = [self::job(self::ID_A), self::job(self::ID_B)];
        $ids = '{"' . self::ID_A . '","' . self::ID_B . '"}';

        $discard = QueueStatements::settle(SettleBatch::of(Settlement::discard($jobs[0], $why), Settlement::discard($jobs[1], $why)), self::token());
        $fail = QueueStatements::settle(SettleBatch::of(Settlement::fail($jobs[0], $why, $at), Settlement::fail($jobs[1], $why, $at)), self::token());
        $reschedule = QueueStatements::settle(SettleBatch::of(Settlement::reschedule($jobs[0], $why, $at)), self::token());

        self::assertStringStartsWith('UPDATE jobs SET discarded_at = now(), last_fail_reason = ?::jsonb, lease_token = NULL', $discard->text);
        self::assertSame(['{"code":"boom"}', $ids, self::TOKEN], $discard->params);

        self::assertStringStartsWith(
            'UPDATE jobs SET available_at = ?::timestamptz, last_fail_reason = ?::jsonb,'
            . ' consecutive_failures = consecutive_failures + 1, consecutive_reschedules = 0, lease_token = NULL',
            $fail->text,
        );
        self::assertSame(['2026-09-11 11:00:00.000000+00:00', '{"code":"boom"}', $ids, self::TOKEN], $fail->params);

        self::assertStringStartsWith(
            'UPDATE jobs SET available_at = ?::timestamptz, last_reschedule_reason = ?::jsonb,'
            . ' consecutive_reschedules = consecutive_reschedules + 1, consecutive_failures = 0, lease_token = NULL',
            $reschedule->text,
        );
        self::assertSame(['2026-09-11 11:00:00.000000+00:00', '{"code":"boom"}', '{"' . self::ID_A . '"}', self::TOKEN], $reschedule->params);
    }

    public function testMixedBatchUsesTheUnnestFormWithPerRowInputs(): void
    {
        $at = Fixtures::at('2026-09-11 11:00:00');
        $batch = SettleBatch::of(
            Settlement::fail(self::job(self::ID_A), new Reason(['code' => 'x']), $at),
            Settlement::release(self::job(self::ID_B)),
        );

        $sql = QueueStatements::settle($batch, self::token());

        self::assertStringStartsWith('UPDATE jobs j SET completed_at = CASE WHEN s.outcome = \'complete\' THEN now() ELSE j.completed_at END,', $sql->text);
        self::assertStringContainsString("consecutive_failures = CASE WHEN s.outcome = 'fail' THEN j.consecutive_failures + 1 WHEN s.outcome = 'reschedule' THEN 0 ELSE j.consecutive_failures END", $sql->text);
        self::assertStringContainsString('FROM unnest(?::uuid[], ?::text[], ?::jsonb[], ?::timestamptz[]) AS s(id, outcome, reason, available_at)', $sql->text);
        self::assertStringEndsWith('WHERE j.id = s.id AND j.lease_token = ? RETURNING j.id', $sql->text);
        self::assertSame([
            '{"' . self::ID_A . '","' . self::ID_B . '"}',
            '{"fail","release"}',
            '{"{\\"code\\":\\"x\\"}",NULL}',
            '{"2026-09-11 11:00:00.000000+00:00",NULL}',
            self::TOKEN,
        ], $sql->params);
    }

    public function testStaleAckTouchesTelemetryOnly(): void
    {
        $sql = QueueStatements::staleAck([JobId::fromString(self::ID_A)], new WorkerId('w1'));

        self::assertSame(
            'UPDATE jobs SET stale_ack_count = stale_ack_count + 1, last_stale_ack_by = ?, last_stale_ack_at = now()'
            . ' WHERE id = ANY(?::uuid[])',
            $sql->text,
        );
        self::assertSame(['w1', '{"' . self::ID_A . '"}'], $sql->params);
    }

    private static function token(): LeaseToken
    {
        return LeaseToken::fromString(self::TOKEN, new WorkerId('w1'));
    }

    private static function job(string $id): \Lezhnev74\Jobs\Model\Job
    {
        return Fixtures::job(id: JobId::fromString($id));
    }
}
