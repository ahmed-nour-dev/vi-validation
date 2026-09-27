<?php

/**
 * Lightweight performance regression gate for CI.
 *
 * Absolute timings vary wildly between CI runners, so the gate checks *ratios measured in the
 * same process on the same machine* against benchmarks/baseline.json:
 *   - speedup of each vi/validation path over Laravel's validator,
 *   - native over engine for natively compilable scenarios,
 *   - peak memory added while validating (must stay bounded).
 * Each measurement is the best of several repetitions to damp noise. Exits non-zero on a
 * regression and writes the measurements to benchmarks/results/regression.json.
 *
 *   php benchmarks/regression.php
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Suite.php';

use Vi\Validation\Benchmarks\Suite;

/** @var array{rows: int, repetitions: int, min_speedup_vs_laravel: array<string, float>, min_native_over_engine: float, max_peak_memory_bytes: int} $baseline */
$baseline = json_decode((string) file_get_contents(__DIR__ . '/baseline.json'), true, 512, JSON_THROW_ON_ERROR);
$suite = new Suite();
$failures = [];
$measurements = [];

foreach (Suite::SCENARIOS as $scenario) {
    $best = [];
    foreach (Suite::PATHS as $path) {
        for ($i = 0; $i < $baseline['repetitions']; $i++) {
            $result = $suite->run($scenario, $path, $baseline['rows']);
            if ($result === null) {
                continue 2;
            }
            if (!isset($best[$path]) || $result['rows_per_sec'] > $best[$path]['rows_per_sec']) {
                $best[$path] = $result;
            }
        }
    }

    $laravel = $best['laravel']['rows_per_sec'];
    foreach ($best as $path => $result) {
        $speedup = $result['rows_per_sec'] / $laravel;
        $measurements[] = ['scenario' => $scenario, 'path' => $path, 'rows_per_sec' => $result['rows_per_sec'],
            'speedup_vs_laravel' => round($speedup, 2), 'peak_memory_bytes' => $result['peak_memory_bytes']];
        printf("%-12s %-8s %10s rows/s  %6.1fx Laravel  peak +%s KB\n", $scenario, $path,
            number_format($result['rows_per_sec']), $speedup, number_format($result['peak_memory_bytes'] / 1024, 1));

        $min = $baseline['min_speedup_vs_laravel'][$path] ?? null;
        if ($min !== null && $speedup < $min) {
            $failures[] = sprintf('%s/%s: %.1fx Laravel, expected >= %.1fx', $scenario, $path, $speedup, $min);
        }
        if ($path !== 'laravel' && $result['peak_memory_bytes'] > $baseline['max_peak_memory_bytes']) {
            $failures[] = sprintf('%s/%s: peak memory +%d bytes, expected <= %d', $scenario, $path, $result['peak_memory_bytes'], $baseline['max_peak_memory_bytes']);
        }
    }

    if (isset($best['native'], $best['engine'])) {
        $ratio = $best['native']['rows_per_sec'] / $best['engine']['rows_per_sec'];
        printf("%-12s native/engine %.2fx\n", $scenario, $ratio);
        if ($ratio < $baseline['min_native_over_engine']) {
            $failures[] = sprintf('%s: native is %.2fx engine, expected >= %.2fx', $scenario, $ratio, $baseline['min_native_over_engine']);
        }
    }
}

@mkdir(__DIR__ . '/results', 0755, true);
file_put_contents(__DIR__ . '/results/regression.json', json_encode([
    'environment' => Suite::environment(),
    'baseline' => $baseline,
    'measurements' => $measurements,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

if ($failures !== []) {
    fwrite(STDERR, "\nPerformance regression:\n  - " . implode("\n  - ", $failures) . "\n");
    exit(1);
}

echo "\nNo performance regression.\n";
