<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Postgres;

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
use PDOStatement;

/** One autocommit statement per call; the SQL lives in `MonitorStatements`. */
final class PostgresMonitor implements JobMonitor
{
    public function __construct(private readonly PDO $pdo) {}

    public function get(JobId $id): ?Job
    {
        $row = $this->run(MonitorStatements::get($id->value))->fetch(PDO::FETCH_ASSOC);

        return \is_array($row) ? JobHydrator::hydrate($row) : null;
    }

    public function find(PoolName $pool, DedupKey $key): ?Job
    {
        $row = $this->run(MonitorStatements::find($pool->value, $key->value))->fetch(PDO::FETCH_ASSOC);

        return \is_array($row) ? JobHydrator::hydrate($row) : null;
    }

    /** @return list<Job> */
    public function read(JobQuery $q): iterable
    {
        $rows = array_filter($this->run(MonitorStatements::read($q))->fetchAll(PDO::FETCH_ASSOC), \is_array(...));

        return array_values(array_map(JobHydrator::hydrate(...), $rows));
    }

    public function count(JobQuery $q): int
    {
        return (int) $this->run(MonitorStatements::count($q))->fetchColumn();
    }

    public function aggregate(JobQuery $q, GroupBy $g): array
    {
        $rows = array_filter($this->run(MonitorStatements::aggregate($q, $g))->fetchAll(PDO::FETCH_ASSOC), \is_array(...));

        return array_values(array_map(self::group(...), $rows));
    }

    public function delete(JobQuery $q): int
    {
        return $this->run(MonitorStatements::delete($q))->rowCount();
    }

    public function deleteIncludingLeased(JobQuery $q): int
    {
        return $this->run(MonitorStatements::deleteIncludingLeased($q))->rowCount();
    }

    public function requeue(JobQuery $q, CarbonImmutable $at, Reason $why): int
    {
        return $this->run(MonitorStatements::requeue($q, $at, $why))->rowCount();
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

    private function run(Sql $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql->text);
        $statement->execute($sql->params);

        return $statement;
    }
}
