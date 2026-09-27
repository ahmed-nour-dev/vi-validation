<?php

/**
 * Schema lifecycle benchmark: separates one-time costs (build/compile, fingerprint, native
 * code generation) from per-request and steady-state costs, for the engine and native paths.
 *
 *   php tests/benchmark_lifecycle.php [rows=100000]
 */

require __DIR__ . '/../vendor/autoload.php';

use Vi\Validation\Compilation\NativeArtifactRepository;
use Vi\Validation\Compilation\ValidatorCompiler;
use Vi\Validation\Laravel\FastValidatorFactory;
use Vi\Validation\SchemaValidator;
use Vi\Validation\Validator;

$rows = (int) ($argv[1] ?? 100000);
$cacheDir = sys_get_temp_dir() . '/vi-validation-bench-' . bin2hex(random_bytes(4));

$rules = [
    'name' => 'required|string|min:3|max:50',
    'email' => 'required|email',
    'age' => 'nullable|integer|min:18|max:120',
    'active' => 'boolean',
    'website' => 'nullable|url',
];

$data = [];
for ($i = 0; $i < $rows; $i++) {
    $data[] = [
        'name' => 'User ' . $i,
        'email' => $i % 10 === 0 ? 'broken' : "user{$i}@example.com",
        'age' => $i % 3 === 0 ? null : 18 + ($i % 60),
        'active' => $i % 2 === 0,
        'website' => $i % 4 === 0 ? null : "https://example.com/{$i}",
    ];
}

$time = static function (callable $fn, int $iterations = 1): float {
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $fn();
    }
    return (hrtime(true) - $start) / 1e6 / $iterations;
};

$fmt = static fn (float $ms): string => $ms < 1 ? number_format($ms * 1000, 1) . ' µs' : number_format($ms, 2) . ' ms';

echo "PHP " . PHP_VERSION . ", OPcache " . (function_exists('opcache_get_status') && @opcache_get_status() ? 'on' : 'off')
    . ", JIT " . (ini_get('opcache.jit') ?: 'off') . ", rows={$rows}\n\n";

// --- one-time costs --------------------------------------------------------------------
$compileMs = $time(static fn () => Validator::fromRules($rules), 200);
$schema = Validator::fromRules($rules);
$fingerprintMs = $time(static fn () => \Vi\Validation\Compilation\SchemaFingerprint::of($schema), 200);

$compiler = new ValidatorCompiler(null, false, $cacheDir);
$codegenMs = $time(static function () use ($compiler, $schema, $cacheDir): void {
    $compiler->clearNative();
    $compiler->writeNativeFor($schema);
}, 50);
NativeArtifactRepository::flushMemory();
$loadMs = $time(static function () use ($compiler, $schema): void {
    NativeArtifactRepository::flushMemory();
    $compiler->loadNativeFor($schema);
}, 200);

echo "One-time costs\n";
echo "  build+compile schema from rules:      " . $fmt($compileMs) . "\n";
echo "  fingerprint:                          " . $fmt($fingerprintMs) . "\n";
echo "  native codegen + verify + persist:    " . $fmt($codegenMs) . "\n";
echo "  artifact load (verify + require):     " . $fmt($loadMs) . "  (once per process)\n\n";

// --- per-request cost (Laravel-style make() + validate one row) ------------------------
NativeArtifactRepository::flushMemory();
$engineFactory = new FastValidatorFactory();
$nativeFactory = new FastValidatorFactory(['compilation' => ['cache_path' => $cacheDir]]);
$nativeFactory->precompile($rules);
$row = $data[1];
$engineReq = $time(static fn () => $engineFactory->make($row, $rules)->passes(), 5000);
$nativeReq = $time(static fn () => $nativeFactory->make($row, $rules)->passes(), 5000);

echo "Per request (factory make() + validate 1 row, warm process)\n";
echo "  engine:  " . $fmt($engineReq) . "\n";
echo "  native:  " . $fmt($nativeReq) . "\n\n";

// --- steady state ----------------------------------------------------------------------
$engine = new SchemaValidator($schema);
$native = new SchemaValidator($schema, null, $compiler);
$native->warm();

$results = [];
foreach (['engine' => $engine, 'native' => $native] as $label => $validator) {
    gc_collect_cycles();
    $memBefore = memory_get_usage();
    $start = hrtime(true);
    $failures = 0;
    foreach ($validator->stream($data) as $result) {
        if (!$result->isValid()) {
            $failures++;
        }
    }
    $seconds = (hrtime(true) - $start) / 1e9;
    $results[$label] = $seconds;
    printf(
        "Steady state %-7s %8.3f s  %12s rows/s  failures=%d  mem delta=%s\n",
        $label . ':',
        $seconds,
        number_format($rows / $seconds),
        $failures,
        number_format(memory_get_usage() - $memBefore) . ' B'
    );
}

printf("\nnative speedup over engine: %.2fx\n", $results['engine'] / $results['native']);

(new ValidatorCompiler(null, false, $cacheDir))->clearNative();
@unlink($cacheDir . '/native/.lock');
@rmdir($cacheDir . '/native');
@rmdir($cacheDir);
