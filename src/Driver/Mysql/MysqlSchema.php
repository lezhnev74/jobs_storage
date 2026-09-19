<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Mysql;

use Lezhnev74\Jobs\Driver\Contract\Schema;
use Lezhnev74\Jobs\Driver\Support\SchemaFile;

/** Never executed by the library at runtime: the test suite applies it to a throwaway database, operators manually. */
final class MysqlSchema implements Schema
{
    public const DIRECTORY = __DIR__ . '/../../../resources/schema/mysql';

    public function statements(): array
    {
        return SchemaFile::statements(self::DIRECTORY, 'schema.sql');
    }

    public function dropStatements(): array
    {
        return SchemaFile::statements(self::DIRECTORY, 'drop.sql');
    }
}
