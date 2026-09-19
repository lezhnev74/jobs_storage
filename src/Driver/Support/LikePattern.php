<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Support;

final class LikePattern
{
    public const ESCAPE = '\\';

    /**
     * `billing_x.` -> `billing\_x.%`. Backslash is the default escape character on both PostgreSQL and MySQL;
     * drivers on backends with a different default add an explicit `ESCAPE` clause.
     */
    public static function prefix(string $literal): string
    {
        return self::escape($literal) . '%';
    }

    public static function escape(string $literal): string
    {
        return strtr($literal, [
            self::ESCAPE => self::ESCAPE . self::ESCAPE,
            '%' => self::ESCAPE . '%',
            '_' => self::ESCAPE . '_',
        ]);
    }
}
