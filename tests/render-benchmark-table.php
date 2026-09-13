<?php

declare(strict_types=1);

/**
 * Renders the README "Performance at a Glance" markdown table from a benchmark-results JSON
 * document (see tests/run-benchmarks.php), so the shipped numbers are regenerated from the
 * suite instead of hand-edited.
 *
 * Usage:
 *   php tests/render-benchmark-table.php [path/to/results.json]
 *   (default path: benchmark-results/latest.json)
 *
 * For each (scenario, size), picks the fastest applicable vi/validation variant - "native" when
 * NativeCompiler could inline the schema, otherwise "compiled" (see the "Native compilation
 * compatibility contract" in README.md for why "complex"/"etl" fall back) - and compares it
 * against "laravel".
 */

function formatSeconds(float $seconds): string
{
    return number_format($seconds, $seconds < 1.0 ? 4 : 2);
}

$path = $argv[1] ?? 'benchmark-results/latest.json';

if (!is_file($path)) {
    fwrite(STDERR, "No results file at $path. Run `composer bench` (or `composer bench:quick`) first.\n");
    exit(1);
}

$document = json_decode((string) file_get_contents($path), true);
if (!is_array($document) || !isset($document['scenarios'])) {
    fwrite(STDERR, "$path does not look like a benchmark-results document.\n");
    exit(1);
}

$labels = [
    'simple' => 'Simple Rules',
    'medium' => 'Medium Rules',
    'complex' => 'Complex Rules',
    'etl' => 'ETL Import',
];

echo "| Scenario | Rows | FastValidator 🚀 | Laravel Validator | Speedup | Throughput |\n";
echo "| :--- | :--- | :--- | :--- | :--- | :--- |\n";

foreach ($document['scenarios'] as $scenarioName => $bySize) {
    foreach ($bySize as $size => $byVariant) {
        $laravel = $byVariant['laravel'] ?? null;
        $fast = $byVariant['native']['supported'] ?? false ? $byVariant['native'] : ($byVariant['compiled'] ?? null);

        if ($laravel === null || $fast === null || ($laravel['supported'] ?? true) === false) {
            continue;
        }

        $laravelSeconds = $laravel['duration_seconds']['median'];
        $fastSeconds = $fast['duration_seconds']['median'];
        $speedup = $fastSeconds > 0 ? $laravelSeconds / $fastSeconds : 0.0;

        printf(
            "| **%s** | %s | **%ss** | %ss | **%sx** | ~%s rows/s |\n",
            $labels[$scenarioName] ?? $scenarioName,
            number_format((int) $size),
            formatSeconds($fastSeconds),
            formatSeconds($laravelSeconds),
            number_format($speedup, 1),
            number_format($fast['rows_per_second'])
        );
    }
}

$environment = $document['environment'] ?? [];
printf(
    "\n> *Benchmarks run on PHP %s%s. Generated %s by `composer bench` - see [Benchmarks](#-benchmarks) "
    . "for methodology and how to reproduce these numbers (raw data: `%s`).*\n",
    $environment['php_version'] ?? 'unknown',
    isset($environment['illuminate_validation_version'])
        ? ', illuminate/validation ' . $environment['illuminate_validation_version']
        : '',
    $document['generated_at'] ?? 'unknown',
    $path
);
