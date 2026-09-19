<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Mysql;

use Carbon\CarbonImmutable;
use Lezhnev74\Jobs\Contract\JobMonitor;
use Lezhnev74\Jobs\Model\DedupKey;
use Lezhnev74\Jobs\Model\Job;
use Lezhnev74\Jobs\Model\JobId;
use Lezhnev74\Jobs\Model\PoolName;
use Lezhnev74\Jobs\Model\Reason;
use Lezhnev74\Jobs\Query\GroupBy;
use Lezhnev74\Jobs\Query\JobQuery;
use PDO;

/**
 * The reads are single autocommit statements; the janitor verbs are one short transaction of a lock-select plus a
 * write by id.
 */
final class MysqlMonitor implements JobMonitor
{
    private readonly Statements $statements;

    public function __construct(PDO $pdo)
    {
        $this->statements = new Statements($pdo);
    }

    public function get(JobId $id): ?Job
    {
        $rows = $this->statements->rows(MonitorStatements::get($id));

        return $rows === [] ? null : JobHydrator::hydrate($rows[0]);
    }

    public function find(PoolName $pool, DedupKey $key): ?Job
    {
        $rows = $this->statements->rows(MonitorStatements::find($pool->value, $key->value));

        return $rows === [] ? null : JobHydrator::hydrate($rows[0]);
    }

    /** @return list<Job> */
    public function read(JobQuery $q): iterable
    {
        return array_map(JobHydrator::hydrate(...), $this->statements->rows(MonitorStatements::read($q)));
    }

    public function count(JobQuery $q): int
    {
        return (int) $this->statements->run(MonitorStatements::count($q))->fetchColumn();
    }

    public function aggregate(JobQuery $q, GroupBy $g): array
    {
        return array_map(self::group(...), $this->statements->rows(MonitorStatements::aggregate($q, $g)));
    }

    /** Skips rows under a live lease. */
    public function delete(JobQuery $q): int
    {
        return $this->sweep($q, skipLeased: true, write: MonitorStatements::delete(...));
    }

    public function deleteIncludingLeased(JobQuery $q): int
    {
        return $this->sweep($q, skipLeased: false, write: MonitorStatements::delete(...));
    }

    public function requeue(JobQuery $q, CarbonImmutable $at, Reason $why): int
    {
        return $this->sweep(
            $q,
            skipLeased: false,
            write: static fn(array $ids): Sql => MonitorStatements::requeue($ids, $at, $why),
        );
    }

    /**
     * The picked ids are the answer: the write goes by primary key against rows this transaction holds locked, so
     * its own row count could only repeat them.
     *
     * @param callable(list<JobId>): Sql $write
     */
    private function sweep(JobQuery $q, bool $skipLeased, callable $write): int
    {
        return $this->statements->lockThenAct(
            MonitorStatements::pick($q, $skipLeased),
            function (array $ids) use ($write): int {
                $this->statements->run($write($ids));

                return \count($ids);
            },
            0,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array{group: ?string, count: int}
     */
    private static function group(array $row): array
    {
        $group = $row['grp'] ?? null;

        return ['group' => $group === null ? null : (string) $group, 'count' => (int) $row['cnt']];
    }
}
