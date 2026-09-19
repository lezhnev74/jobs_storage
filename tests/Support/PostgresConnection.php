<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Tests\Support;

use PDO;
use RuntimeException;

final class PostgresConnection
{
    public static function open(): PDO
    {
        return new PDO(
            self::env('JOBS_PG_DSN'),
            self::env('JOBS_PG_USER'),
            self::env('JOBS_PG_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
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
