<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Support;

use RuntimeException;

final class SchemaFile
{
    /** @return list<string> */
    public static function statements(string $directory, string $file): array
    {
        $path = $directory . '/' . $file;
        $script = @file_get_contents($path);
        if ($script === false) {
            throw new RuntimeException(\sprintf('Cannot read schema file %s', $path));
        }

        return SqlScript::statements($script);
    }
}
