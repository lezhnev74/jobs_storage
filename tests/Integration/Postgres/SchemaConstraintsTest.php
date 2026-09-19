<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Integration\Postgres;

use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The CHECK constraints reject every illegal row shape, which is what lets the nullable submodels of `Job` mirror
 * them one-to-one.
 */
final class SchemaConstraintsTest extends PostgresTestCase
{
    private const CHECK_VIOLATION = '23514';

    /** @return iterable<string, array{string, string}> */
    public static function illegalShapes(): iterable
    {
        yield 'both terminal markers' => ['completed_at, discarded_at', 'now(), now()'];
        yield 'token without expiry' => ['lease_token', 'gen_random_uuid()'];
        yield 'expiry without token' => ['consumed_till', 'now()'];
        yield 'token on a completed row' => ['lease_token, consumed_till, completed_at', 'gen_random_uuid(), now(), now()'];
        yield 'token on a discarded row' => ['lease_token, consumed_till, discarded_at', 'gen_random_uuid(), now(), now()'];
    }

    #[DataProvider('illegalShapes')]
    public function testIllegalShapesAreRejected(string $columns, string $values): void
    {
        try {
            $this->insert($columns, $values);
            self::fail('the CHECK constraint must reject the row');
        } catch (PDOException $e) {
            self::assertSame(self::CHECK_VIOLATION, $e->getCode());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function legalShapes(): iterable
    {
        yield 'plain' => ['consumed_by', "'w1'"];
        yield 'leased' => ['lease_token, consumed_till', 'gen_random_uuid(), now()'];
        yield 'completed' => ['completed_at', 'now()'];
        yield 'discarded with an expired lease cleared' => ['discarded_at, consumed_by', "now(), 'w1'"];
    }

    #[DataProvider('legalShapes')]
    public function testLegalShapesAreAccepted(string $columns, string $values): void
    {
        $this->insert($columns, $values);

        $count = $this->pdo->prepare('SELECT count(*) FROM jobs');
        $count->execute();

        self::assertSame(1, (int) $count->fetchColumn());
    }

    public function testUpdatingIntoAnIllegalShapeIsRejectedToo(): void
    {
        $this->insert('lease_token, consumed_till', 'gen_random_uuid(), now()');

        try {
            $this->pdo->exec('UPDATE jobs SET completed_at = now()');
            self::fail('a terminal row never carries a token');
        } catch (PDOException $e) {
            self::assertSame(self::CHECK_VIOLATION, $e->getCode());
        }
    }

    private function insert(string $columns, string $values): void
    {
        $this->pdo->exec(\sprintf(
            'INSERT INTO jobs (id, name, pool, available_at, dedup_key, %s)'
            . " VALUES (gen_random_uuid(), 'a.b', 'p', now(), gen_random_uuid()::text, %s)",
            $columns,
            $values,
        ));
    }
}
