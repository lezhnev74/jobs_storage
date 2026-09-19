<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Postgres;

/**
 * One SQL text with its positional (`?`) parameters, in order of appearance. Every parameter is sent as text (or
 * NULL) and cast server-side (`?::uuid[]`, `?::interval`, ...), so PDO never needs typed binding.
 *
 * Statements are authored as indented `<<<SQL` heredocs and folded here into a single spaced line: the texts stay
 * comparable and log-friendly, and a fragment can be interpolated into a larger heredoc without carrying its own
 * newlines along.
 *
 * The folding is textual and knows nothing of quoting, so it would also collapse whitespace inside a SQL string
 * literal. Every literal these statements embed is a single bare token (`'complete'`, `'.'`); any future one
 * containing spaces must be sent as a parameter rather than inlined.
 */
final readonly class Sql
{
    public string $text;

    /** @param list<string|null> $params */
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
