<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Support;

use PDO;
use RuntimeException;

final class MysqlConnection
{
    /** A fresh connection every call, with the attributes `MysqlDriver` requires. */
    public static function open(): PDO
    {
        return new PDO(
            self::env('JOBS_MYSQL_DSN'),
            self::env('JOBS_MYSQL_USER'),
            self::env('JOBS_MYSQL_PASSWORD'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_FOUND_ROWS => true,
            ],
        );
    }

    private static function env(string $name): string
    {
        $value = getenv($name);
        if (!\is_string($value) || $value === '') {
            throw new RuntimeException(\sprintf('%s is not set; see phpunit.xml.dist', $name));
        }

        return $value;
    }
}
