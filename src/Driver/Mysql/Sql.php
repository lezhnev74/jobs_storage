<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Mysql;

/**
 * Parameters travel as strings (or NULL) and the statement casts them server-side (`UUID_TO_BIN(?)`,
 * `CAST(? AS JSON)`, ...), with one exception: `LIMIT` takes an integer, which native prepares bind as such.
 *
 * The constructor folds heredoc layout into a single spaced line, so a fragment can be interpolated into a larger
 * heredoc without carrying its own newlines along. The folding is textual and knows nothing of quoting, so it would
 * also collapse whitespace inside a SQL string literal: every literal these statements embed is a single bare token
 * (`'complete'`, `'.'`), and any future one containing spaces must be sent as a parameter rather than inlined.
 */
final readonly class Sql
{
    public string $text;

    /** @param list<string|int|null> $params */
    public function __construct(
        string $text,
        public array $params = [],
    ) {
        $this->text = self::flatten($text);
    }

    private static function flatten(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
