<?php

/**
 * Error collection strategies for large imports: time and retained memory per ErrorMode when
 * validating a dataset where half of the rows are invalid, versus naively keeping every
 * failure in an array.
 *
 *   php tests/benchmark_error_modes.php [rows=200000]
 */

require __DIR__ . '/../vendor/autoload.php';

use Vi\Validation\Execution\ErrorMode;
use Vi\Validation\Execution\ValidatorEngine;
use Vi\Validation\SchemaValidator;
use Vi\Validation\Validator;

$rows = (int) ($argv[1] ?? 200_000);

$schema = Validator::fromRules([
    'sku' => 'required|string|min:6|max:20|alpha_dash',
    'email' => 'required|email',
    'qty' => 'required|integer|min:1|max:10000',
    'price' => 'required|numeric|min:0',
    'note' => 'nullable|string|max:200',
]);

$source = static function (int $count): Generator {
    for ($i = 0; $i < $count; $i++) {
        yield $i % 2 === 0
            ? ['sku' => 'SKU-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'email' => "b{$i}@example.com", 'qty' => 5, 'price' => 9.99]
            : ['sku' => '!', 'email' => 'broken', 'qty' => 0, 'price' => 'free', 'note' => str_repeat('x', 300)];
    }
};

$measure = static function (callable $fn): array {
    gc_collect_cycles();
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $base = memory_get_usage();
    $start = hrtime(true);
    $keep = $fn();
    $seconds = (hrtime(true) - $start) / 1e9;
    $peak = memory_get_peak_usage() - $base;
    unset($keep);

    return [$seconds, $peak];
};

printf("PHP %s, rows=%s (50%% invalid)\n\n", PHP_VERSION, number_format($rows));
printf("%-44s %9s %12s %14s\n", 'strategy', 'time', 'rows/s', 'peak added');

$run = static function (string $label, ErrorMode $mode, callable $consume) use ($schema, $source, $rows, $measure): void {
    $validator = new SchemaValidator($schema, new ValidatorEngine(null, false, 100, $mode));
    [$t, $mem] = $measure(static fn () => $consume($validator, $source($rows)));
    printf("%-44s %8.3fs %12s %11s KB\n", $label, $t, number_format($rows / $t), number_format($mem / 1024));
};

$keepAll = static fn (SchemaValidator $v, iterable $rows) => iterator_to_array($v->failures($rows));
$report = static fn (SchemaValidator $v, iterable $rows) => $v->report($rows, 100);

$run('All, keep every failure (naive)', ErrorMode::All, $keepAll);
$run('FirstPerField, keep every failure', ErrorMode::FirstPerField, $keepAll);
$run('All + report(100)', ErrorMode::All, $report);
$run('FirstPerField + report(100)', ErrorMode::FirstPerField, $report);
$run('FirstPerRow + report(100)', ErrorMode::FirstPerRow, $report);
$run('CountOnly + report(0)', ErrorMode::CountOnly, static fn (SchemaValidator $v, iterable $rows) => $v->report($rows, 0));
