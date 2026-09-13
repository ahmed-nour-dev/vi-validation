<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Vi\Validation\Tests\Benchmarks\Environment;
use Vi\Validation\Tests\Benchmarks\Runner;
use Vi\Validation\Tests\Benchmarks\ScenarioRegistry;
use Vi\Validation\Tests\Benchmarks\Stats;

/**
 * Reproducible benchmark suite for vi/validation vs Laravel's validator (issue #9).
 *
 * Usage (from a clean checkout, after `composer install`):
 *   php tests/run-benchmarks.php                              # full suite, sizes up to 100k
 *   php tests/run-benchmarks.php --large                      # also include 1,000,000 rows
 *   php tests/run-benchmarks.php --scenarios=simple,medium --sizes=1000,10000
 *   php tests/run-benchmarks.php --out=benchmark-results/run.json
 *   php tests/run-benchmarks.php --help
 *
 * Or via the composer shortcuts: `composer bench` / `composer bench:quick`.
 *
 * Each (scenario, variant, size) combination is measured in its own PHP subprocess (worker
 * mode below, `--worker`) so memory_get_peak_usage() readings never leak between combinations
 * - PHP provides no way to reset that counter mid-process. This is slower than running
 * everything in one process, which is why the CI regression gate
 * (tests/Unit/Performance/RegressionBenchmarkTest.php) measures in-process instead: it only
 * needs a timing ratio, not memory, and needs to run in well under a second.
 */

$options = parseArgs($argv);

if (isset($options['worker'])) {
    runWorker($options);
    exit(0);
}

runOrchestrator($options);
exit(0);

/**
 * @param list<string> $argv
 * @return array<string, string|true>
 */
function parseArgs(array $argv): array
{
    $options = [];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }

        if (!str_starts_with($arg, '--')) {
            continue;
        }

        $arg = substr($arg, 2);
        if (str_contains($arg, '=')) {
            [$key, $value] = explode('=', $arg, 2);
            $options[$key] = $value;
        } else {
            $options[$arg] = true;
        }
    }

    return $options;
}

/**
 * @param array<string, string|true> $options
 */
function runWorker(array $options): void
{
    $scenario = ScenarioRegistry::get((string) ($options['scenario'] ?? ''));
    $variant = (string) ($options['variant'] ?? '');
    $size = (int) ($options['size'] ?? 1000);
    $trials = (int) ($options['trials'] ?? 5);
    $warmup = (int) ($options['warmup'] ?? 2);

    $rows = $scenario->rows($size);
    $runner = new Runner();

    $result = match ($variant) {
        'laravel' => $runner->measureLaravel($scenario->rules, $rows, $trials, $warmup),
        'engine' => $runner->measureEngine($scenario->rules, $rows, $trials, $warmup),
        'compiled' => $runner->measureCompiled($scenario->rules, $rows, $trials, $warmup),
        'native' => runNativeVariant($runner, $scenario->rules, $rows, $trials, $warmup),
        default => throw new \InvalidArgumentException("Unknown variant '$variant'."),
    };

    $output = [
        'scenario' => $scenario->name,
        'variant' => $variant,
        'size' => $size,
        'result' => $result,
        'peak_memory_bytes' => memory_get_peak_usage(false),
        'peak_memory_real_bytes' => memory_get_peak_usage(true),
        // Captured here, not in the orchestrator process, so opcache_enabled_cli/jit reflect
        // the actual worker subprocess the numbers were measured in (the orchestrator always
        // launches workers with `-d opcache.enable_cli=1`; its own process doesn't have that set).
        'environment' => Environment::capture(),
    ];

    fwrite(STDOUT, json_encode($output) . "\n");
}

/**
 * @param array<string, string> $rules
 * @param list<array<string, mixed>> $rows
 * @return array<string, mixed>
 */
function runNativeVariant(Runner $runner, array $rules, array $rows, int $trials, int $warmup): array
{
    $cacheDir = sys_get_temp_dir() . '/vi-validation-bench-native-' . bin2hex(random_bytes(8));

    try {
        return $runner->measureNative($rules, $rows, $trials, $warmup, $cacheDir);
    } finally {
        removeDirectory($cacheDir);
    }
}

function removeDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            removeDirectory($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

/**
 * @param array<string, string|true> $options
 */
function runOrchestrator(array $options): void
{
    if (isset($options['help'])) {
        printHelp();
        return;
    }

    $scenarios = isset($options['scenarios'])
        ? explode(',', (string) $options['scenarios'])
        : array_keys(ScenarioRegistry::all());

    $variants = isset($options['variants'])
        ? explode(',', (string) $options['variants'])
        : ['laravel', 'engine', 'compiled', 'native'];

    $sizes = isset($options['sizes'])
        ? array_map('intval', explode(',', (string) $options['sizes']))
        : [1000, 10000, 100000];

    if (isset($options['large']) && !in_array(1_000_000, $sizes, true)) {
        $sizes[] = 1_000_000;
    }

    $trials = (int) ($options['trials'] ?? 5);
    $warmup = (int) ($options['warmup'] ?? 2);
    $out = (string) ($options['out'] ?? 'benchmark-results/latest.json');

    $results = [];
    $environment = null;

    foreach ($scenarios as $scenarioName) {
        // Validates the scenario name exists before spending time shelling out for it.
        ScenarioRegistry::get($scenarioName);

        foreach ($sizes as $size) {
            foreach ($variants as $variant) {
                fwrite(STDERR, sprintf("Running %s / %s / %d rows...\n", $scenarioName, $variant, $size));

                $decoded = runWorkerProcess($scenarioName, $variant, $size, $trials, $warmup);
                $environment ??= $decoded['environment'] ?? null;
                $results[$scenarioName][$size][$variant] = summarizeWorkerOutput($decoded);
            }
        }
    }

    $document = [
        'generated_at' => date('c'),
        'environment' => $environment ?? Environment::capture(),
        'trials' => $trials,
        'warmup' => $warmup,
        'scenarios' => $results,
    ];

    $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        fwrite(STDERR, "Failed to encode results as JSON.\n");
        exit(1);
    }

    $dir = dirname($out);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($out, $json . "\n");

    $historyPath = $dir . '/run-' . date('Ymd-His') . '.json';
    file_put_contents($historyPath, $json . "\n");

    fwrite(STDOUT, "\nResults written to $out (and $historyPath)\n\n");
    printSummaryTable($results);
}

/**
 * @return array<string, mixed>
 */
function runWorkerProcess(string $scenarioName, string $variant, int $size, int $trials, int $warmup): array
{
    $cmd = [
        PHP_BINARY,
        '-d', 'opcache.enable_cli=1',
        __FILE__,
        '--worker',
        '--scenario=' . $scenarioName,
        '--variant=' . $variant,
        '--size=' . $size,
        '--trials=' . $trials,
        '--warmup=' . $warmup,
    ];

    // stderr is redirected straight to a file rather than a second pipe: reading two pipes
    // sequentially (stdout fully, then stderr) deadlocks if the child writes enough to stderr
    // to fill its OS pipe buffer before finishing - e.g. a rule that warns per-row across
    // thousands of rows - since the child then blocks on that write while we're still blocked
    // waiting for stdout to close. A file has no such buffer limit.
    $stderrFile = tempnam(sys_get_temp_dir(), 'vi-validation-bench-stderr-');
    $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', $stderrFile, 'w']], $pipes);
    if ($process === false) {
        fwrite(STDERR, "Failed to launch worker process for $scenarioName/$variant/$size.\n");
        @unlink($stderrFile);
        exit(1);
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exitCode = proc_close($process);

    $stderr = (string) file_get_contents($stderrFile);
    @unlink($stderrFile);

    if ($exitCode !== 0) {
        fwrite(STDERR, "Worker failed ($scenarioName/$variant/$size):\n$stderr\n");
        exit(1);
    }

    $decoded = json_decode(trim((string) $stdout), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "Worker produced invalid output ($scenarioName/$variant/$size):\n$stdout\n");
        exit(1);
    }

    return $decoded;
}

/**
 * @param array<string, mixed> $decoded
 * @return array<string, mixed>
 */
function summarizeWorkerOutput(array $decoded): array
{
    $result = $decoded['result'];

    if (($result['supported'] ?? true) === false) {
        return ['supported' => false];
    }

    $stats = Stats::summarize($result['durations'] ?? []);
    $size = $decoded['size'];

    return [
        'supported' => true,
        'duration_seconds' => $stats,
        'rows_per_second' => $stats['median'] > 0 ? $size / $stats['median'] : 0.0,
        'failures' => $result['failures'] ?? null,
        'compile_time_seconds' => $result['compile_time'] ?? null,
        'native_gen_time_seconds' => $result['native_gen_time'] ?? null,
        'native_write_time_seconds' => $result['native_write_time'] ?? null,
        'cold_time_seconds' => $result['cold_time'] ?? null,
        'peak_memory_bytes' => $decoded['peak_memory_bytes'],
        'peak_memory_real_bytes' => $decoded['peak_memory_real_bytes'],
    ];
}

/**
 * @param array<string, mixed> $results
 */
function printSummaryTable(array $results): void
{
    printf("%-8s %10s %-9s %14s %10s\n", 'Scenario', 'Rows', 'Variant', 'rows/sec', 'PeakMemMB');

    foreach ($results as $scenarioName => $bySize) {
        foreach ($bySize as $size => $byVariant) {
            foreach ($byVariant as $variant => $summary) {
                if (($summary['supported'] ?? true) === false) {
                    printf("%-8s %10d %-9s %14s %10s\n", $scenarioName, $size, $variant, 'n/a', 'n/a');
                    continue;
                }

                printf(
                    "%-8s %10d %-9s %14s %10.2f\n",
                    $scenarioName,
                    $size,
                    $variant,
                    number_format($summary['rows_per_second'], 0),
                    $summary['peak_memory_real_bytes'] / 1024 / 1024
                );
            }
        }
    }
}

function printHelp(): void
{
    fwrite(STDOUT, <<<'TEXT'
Reproducible benchmark suite for vi/validation (see README.md "Benchmarks" section).

Usage:
  php tests/run-benchmarks.php [options]

Options:
  --scenarios=simple,medium,complex,etl      Which scenarios to run (default: all)
  --variants=laravel,engine,compiled,native  Which variants to run (default: all)
  --sizes=1000,10000,100000                  Row counts to run (default: 1000,10000,100000)
  --large                                    Also run 1,000,000 rows
  --trials=5                                 Timed trials per combination (default: 5)
  --warmup=2                                 Warmup iterations discarded before timing (default: 2)
  --out=benchmark-results/latest.json        Where to write the results JSON

Each combination runs in its own PHP subprocess for a clean peak-memory reading, so a full run
takes noticeably longer than a single-process microbenchmark - use --scenarios/--sizes/--variants
to narrow it down for a quick check (see also `composer bench:quick`).

TEXT);
}
