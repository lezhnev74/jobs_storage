<?php

declare(strict_types=1);

/** Usage: php tools/crap-check.php build/crap4j.xml 6 */

$argv = $_SERVER['argv'] ?? [];
if (!is_array($argv) || count($argv) < 2) {
    fwrite(STDERR, "usage: crap-check.php <crap4j.xml> [threshold=6]\n");
    exit(2);
}

$file = $argv[1];
$threshold = (float) ($argv[2] ?? 6);

if (!is_file($file)) {
    fwrite(STDERR, "crap-check: report not found: {$file}\n");
    exit(2);
}

$xml = simplexml_load_file($file);
if ($xml === false) {
    fwrite(STDERR, "crap-check: cannot parse {$file}\n");
    exit(2);
}

$offenders = [];
foreach ($xml->methods->method ?? [] as $method) {
    $crap = (float) $method->crap;
    if ($crap > $threshold) {
        $offenders[] = sprintf('%6.1f  %s', $crap, (string) $method->fullMethod);
    }
}

if ($offenders === []) {
    echo "crap-check: all methods within CRAP <= {$threshold}\n";
    exit(0);
}

rsort($offenders);
fwrite(STDERR, "crap-check: methods above CRAP {$threshold}:\n" . implode("\n", $offenders) . "\n");
exit(1);
