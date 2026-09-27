<?php

/**
 * Streaming memory + throughput benchmark: validates generator-backed datasets of 10k, 100k
 * and 1M rows through stream()/failures() on the engine and native paths, and reports the
 * peak memory *added* by validation. That figure should stay flat as the dataset grows.
 * validateMany() is included at the smaller sizes for contrast (it materializes all results).
 *
 *   php tests/benchmark_streaming.php [maxRows=1000000]
 */

require __DIR__ . '/../vendor/autoload.php';

use Vi\Validation\Compilation\NativeArtifactRepository;
use Vi\Validation\Compilation\ValidatorCompiler;
use Vi\Validation\SchemaValidator;
use Vi\Validation\Validator;

$maxRows = (int) ($argv[1] ?? 1_000_000);
$cacheDir = sys_get_temp_dir() . '/vi-validation-stream-bench-' . bin2hex(random_bytes(4));

$schema = Validator::fromRules([
    'id' => 'required|integer|min:1',
    'email' => 'required|email',
    'name' => 'nullable|string|max:100',
    'age' => 'nullable|integer|min:0|max:150',
]);

$rows = static function (int $count): Generator {
    for ($i = 1; $i <= $count; $i++) {
        yield [
            'id' => $i,
            'email' => $i % 20 === 0 ? 'broken' : "user{$i}@example.com",
            'name' => "User {$i}",
            'age' => $i % 7 === 0 ? null : $i % 90,
        ];
    }
};

$validators = [
    'engine' => new SchemaValidator($schema),
    'native' => new SchemaValidator($schema, null, new ValidatorCompiler(null, true, $cacheDir)),
];
$validators['native']->warm();

$resetPeak = static function (): void {
    gc_collect_cycles();
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
};

$measure = static function (callable $fn) use ($resetPeak): array {
    $resetPeak();
    $base = memory_get_usage();
    $start = hrtime(true);
    $fn();
    $seconds = (hrtime(true) - $start) / 1e9;

    return [$seconds, memory_get_peak_usage() - $base];
};

$kb = static fn (int $bytes): string => number_format($bytes / 1024, 1) . ' KB';

printf("PHP %s%s\n\n", PHP_VERSION, function_exists('memory_reset_peak_usage') ? '' : ' (no memory_reset_peak_usage(): peak figures are process-wide)');
printf("%-10s %-8s %-12s %10s %14s %14s\n", 'rows', 'path', 'method', 'time', 'rows/s', 'peak added');

foreach ([10_000, 100_000, 1_000_000] as $count) {
    if ($count > $maxRows) {
        break;
    }

    foreach ($validators as $label => $validator) {
        [$t, $mem] = $measure(static function () use ($validator, $rows, $count): void {
            foreach ($validator->stream($rows($count)) as $result) {
                $result->isValid();
            }
        });
        printf("%-10s %-8s %-12s %9.3fs %14s %14s\n", number_format($count), $label, 'stream()', $t, number_format($count / $t), $kb($mem));

        [$t, $mem] = $measure(static function () use ($validator, $rows, $count): void {
            foreach ($validator->failures($rows($count)) as $result) {
                $result->errors();
            }
        });
        printf("%-10s %-8s %-12s %9.3fs %14s %14s\n", number_format($count), $label, 'failures()', $t, number_format($count / $t), $kb($mem));

        if ($count <= 100_000) {
            [$t, $mem] = $measure(static function () use ($validator, $rows, $count): void {
                $validator->validateMany($rows($count));
            });
            printf("%-10s %-8s %-12s %9.3fs %14s %14s\n", number_format($count), $label, 'validateMany', $t, number_format($count / $t), $kb($mem));
        }
    }
}

(new ValidatorCompiler(null, false, $cacheDir))->clearNative();
NativeArtifactRepository::flushMemory();
@unlink($cacheDir . '/native/.lock');
@rmdir($cacheDir . '/native');
@rmdir($cacheDir);
