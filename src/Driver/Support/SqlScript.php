<?php

declare(strict_types=1);

namespace Lezhnev74\Jobs\Driver\Support;

/**
 * Splits a DDL script on `;` boundaries, dropping `--` and block comments. Quoted strings, quoted identifiers and
 * dollar-quoted bodies are opaque, so a `;` or `--` inside them never splits or comments anything.
 *
 * Only as good as the script it is pointed at: it knows nothing of `DELIMITER` and reads backslashes inside quotes
 * literally, so MySQL's `NO_BACKSLASH_ESCAPES`-off escaping would confuse it. The bundled schemas use neither.
 */
final class SqlScript
{
    private const TOKEN = <<<'RE'
        ~(?<comment>--[^\n]*|/\*.*?\*/)
        |(?<literal>'(?:[^']|'')*'|"(?:[^"]|"")*"|\$(?<tag>[A-Za-z_]*)\$.*?\$\k<tag>\$)
        |(?<end>;)
        |(?<text>[^-/'"$;]+|.)~sx
        RE;

    /** @return list<string> */
    public static function statements(string $script): array
    {
        preg_match_all(self::TOKEN, $script, $tokens, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        $statements = [];
        $current = '';
        foreach ($tokens as $token) {
            if ($token['end'] !== null) {
                $statements[] = $current;
                $current = '';
                continue;
            }
            $current .= $token['literal'] ?? $token['text'] ?? '';
        }
        $statements[] = $current;

        return self::nonEmpty($statements);
    }

    /**
     * @param list<string> $statements
     * @return list<string>
     */
    private static function nonEmpty(array $statements): array
    {
        $trimmed = array_map(trim(...), $statements);

        return array_values(array_filter($trimmed, static fn(string $s): bool => $s !== ''));
    }
}
