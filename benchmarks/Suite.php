<?php

declare(strict_types=1);

namespace Vi\Validation\Benchmarks;

use Generator;
use Illuminate\Container\Container;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as LaravelFactory;
use Vi\Validation\Compilation\NativeArtifactRepository;
use Vi\Validation\Compilation\ValidatorCompiler;
use Vi\Validation\Laravel\FastValidatorFactory;
use Vi\Validation\SchemaValidator;
use Vi\Validation\Validator;

/**
 * Reproducible benchmark suite (see benchmarks/README.md).
 *
 * Datasets are generated deterministically (seeded) and streamed from generators, so every run
 * validates exactly the same rows without holding them in memory. Each scenario is a realistic
 * import/API workload; each "path" is one way of validating it:
 *
 * - laravel:  Illuminate\Validation Factory::make() per row (the baseline)
 * - factory:  vi/validation FastValidatorFactory::make() per row (Laravel-style API, per-request cost)
 * - engine:   one reused SchemaValidator running ValidatorEngine (compile once, validate many)
 * - native:   one reused SchemaValidator running the generated native closure
 *
 * One-time costs (schema compile, native code generation, artifact load) are measured
 * separately from steady-state throughput.
 */
final class Suite
{
    public const SCENARIOS = ['user_import', 'order_lines', 'api_payload'];
    public const PATHS = ['laravel', 'factory', 'engine', 'native'];

    private string $cacheDir;

    public function __construct()
    {
        $this->cacheDir = sys_get_temp_dir() . '/vi-validation-bench-' . getmypid();
    }

    public function __destruct()
    {
        if (is_dir($this->cacheDir)) {
            (new ValidatorCompiler(null, false, $this->cacheDir))->clearNative();
            @unlink($this->cacheDir . '/native/.lock');
            @rmdir($this->cacheDir . '/native');
            @rmdir($this->cacheDir);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(string $scenario): array
    {
        return match ($scenario) {
            // CSV user import: strings, email, optional numbers/urls, flags.
            'user_import' => [
                'name' => 'required|string|min:2|max:100',
                'email' => 'required|email|max:255',
                'age' => 'nullable|integer|min:13|max:120',
                'active' => 'required|boolean',
                'website' => 'nullable|url',
                'username' => 'required|alpha_dash|min:3|max:30',
            ],
            // ETL order lines: codes, quantities, money, enumerations, dates.
            'order_lines' => [
                'order_id' => 'required|integer|min:1',
                'sku' => 'required|alpha_dash|max:32',
                'quantity' => 'required|integer|min:1|max:10000',
                'unit_price' => 'required|numeric|min:0',
                'currency' => 'required|in:USD,EUR,GBP,EGP',
                'shipped_at' => 'nullable|date_format:Y-m-d',
                'coupon' => 'nullable|string|max:20',
            ],
            // Nested JSON API payload with conditional and array rules.
            'api_payload' => [
                'customer.name' => 'required|string|max:100',
                'customer.email' => 'required|email',
                'customer.phone' => 'nullable|regex:/^\+?[0-9 ]{7,15}$/',
                'shipping.method' => 'required|in:standard,express',
                'shipping.address.city' => 'required|string|max:60',
                'shipping.address.zip' => 'required_if:shipping.method,express|nullable|digits_between:4,10',
                'tags' => 'nullable|array|max:5',
                'notes' => 'nullable|string|max:500',
            ],
            default => throw new \InvalidArgumentException("Unknown scenario {$scenario}"),
        };
    }

    /**
     * Deterministic rows; roughly 1 in 10 is invalid in some way.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public static function rows(string $scenario, int $count): Generator
    {
        mt_srand(crc32($scenario));

        for ($i = 0; $i < $count; $i++) {
            $bad = mt_rand(1, 10) === 1;
            yield match ($scenario) {
                'user_import' => [
                    'name' => $bad ? 'X' : 'User ' . $i,
                    'email' => $bad && $i % 2 ? 'not-an-email' : "user{$i}@example.com",
                    'age' => $i % 4 === 0 ? null : 13 + ($i % 90),
                    'active' => $i % 2 === 0,
                    'website' => $i % 3 === 0 ? null : "https://example.com/u/{$i}",
                    'username' => 'user_' . $i,
                ],
                'order_lines' => [
                    'order_id' => 1 + intdiv($i, 5),
                    'sku' => $bad ? 'SKU #' . $i : 'SKU-' . str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                    'quantity' => $bad && $i % 2 ? 0 : 1 + ($i % 50),
                    'unit_price' => round(($i % 1000) / 7, 2),
                    'currency' => ['USD', 'EUR', 'GBP', 'EGP'][$i % 4],
                    'shipped_at' => $i % 5 === 0 ? null : sprintf('2024-%02d-%02d', 1 + $i % 12, 1 + $i % 28),
                    'coupon' => $i % 7 === 0 ? 'SAVE' . ($i % 100) : null,
                ],
                'api_payload' => [
                    'customer' => [
                        'name' => 'Customer ' . $i,
                        'email' => $bad ? 'broken@' : "c{$i}@example.org",
                        'phone' => $i % 3 === 0 ? null : '+20 100 ' . str_pad((string) ($i % 10000000), 7, '0', STR_PAD_LEFT),
                    ],
                    'shipping' => [
                        'method' => $i % 4 === 0 ? 'express' : 'standard',
                        'address' => ['city' => 'Cairo', 'zip' => $i % 4 === 0 && !$bad ? '11511' : null],
                    ],
                    'tags' => $i % 2 === 0 ? ['vip', 'b2b'] : null,
                    'notes' => $i % 9 === 0 ? str_repeat('n', 40) : null,
                ],
            };
        }
    }

    /**
     * Rules for Laravel (dot-notation keys work there as-is).
     *
     * @return array<string, mixed>
     */
    private function laravelFactory(): LaravelFactory
    {
        return new LaravelFactory(new Translator(new ArrayLoader(), 'en'), new Container());
    }

    /**
     * @return array{compile_ms: float, codegen_ms: float|null, load_ms: float|null, native_compilable: bool}
     */
    public function oneTimeCosts(string $scenario): array
    {
        $rules = self::rules($scenario);

        $compileMs = $this->timeMs(static fn () => Validator::fromRules($rules), 50);
        $schema = Validator::fromRules($rules);

        $compiler = new ValidatorCompiler(null, false, $this->cacheDir);
        $compiler->clearNative();
        $start = hrtime(true);
        $path = $compiler->writeNativeFor($schema);
        $codegenMs = (hrtime(true) - $start) / 1e6;

        $loadMs = null;
        if ($path !== null) {
            NativeArtifactRepository::flushMemory();
            $start = hrtime(true);
            $compiler->loadNativeFor($schema);
            $loadMs = (hrtime(true) - $start) / 1e6;
        }

        return [
            'compile_ms' => round($compileMs, 4),
            'codegen_ms' => $path !== null ? round($codegenMs, 4) : null,
            'load_ms' => $loadMs !== null ? round($loadMs, 4) : null,
            'native_compilable' => $path !== null,
        ];
    }

    /**
     * Validate $rows rows of $scenario through $path, measuring the steady state (the one-time
     * setup is done, and one warm-up row validated, before the clock starts).
     *
     * @return array{rows: int, seconds: float, rows_per_sec: int, peak_memory_bytes: int, failures: int}|null
     */
    public function run(string $scenario, string $path, int $rows): ?array
    {
        $rules = self::rules($scenario);
        $validate = $this->validatorFor($scenario, $path, $rules);
        if ($validate === null) {
            return null;
        }

        foreach (self::rows($scenario, 1) as $warmup) {
            $validate($warmup);
        }

        gc_collect_cycles();
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $baseline = memory_get_usage();
        $failures = 0;

        $start = hrtime(true);
        foreach (self::rows($scenario, $rows) as $row) {
            if (!$validate($row)) {
                $failures++;
            }
        }
        $seconds = (hrtime(true) - $start) / 1e9;

        return [
            'rows' => $rows,
            'seconds' => round($seconds, 4),
            'rows_per_sec' => (int) round($rows / max($seconds, 1e-9)),
            'peak_memory_bytes' => max(0, memory_get_peak_usage() - $baseline),
            'failures' => $failures,
        ];
    }

    /**
     * @param array<string, mixed> $rules
     * @return (callable(array<string, mixed>): bool)|null
     */
    private function validatorFor(string $scenario, string $path, array $rules): ?callable
    {
        switch ($path) {
            case 'laravel':
                $factory = $this->laravelFactory();
                return static fn (array $row): bool => $factory->make($row, $rules)->passes();

            case 'factory':
                $factory = new FastValidatorFactory();
                return static fn (array $row): bool => $factory->make($row, $rules)->passes();

            case 'engine':
                $validator = new SchemaValidator(Validator::fromRules($rules));
                return static fn (array $row): bool => $validator->validate($row)->isValid();

            case 'native':
                $validator = new SchemaValidator(
                    Validator::fromRules($rules),
                    null,
                    new ValidatorCompiler(null, true, $this->cacheDir)
                );
                if (!$validator->warm()) {
                    return null; // scenario isn't natively compilable
                }
                return static fn (array $row): bool => $validator->validate($row)->isValid();
        }

        throw new \InvalidArgumentException("Unknown path {$path}");
    }

    /**
     * @return array<string, mixed>
     */
    public static function environment(): array
    {
        $cpu = null;
        if (is_readable('/proc/cpuinfo') && preg_match('/model name\s*:\s*(.+)/', (string) file_get_contents('/proc/cpuinfo'), $m)) {
            $cpu = trim($m[1]);
        }
        $memory = null;
        if (is_readable('/proc/meminfo') && preg_match('/MemTotal:\s*(\d+)/', (string) file_get_contents('/proc/meminfo'), $m)) {
            $memory = (int) $m[1] * 1024;
        }
        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;

        return [
            'php' => PHP_VERSION,
            'laravel_validation' => \Composer\InstalledVersions::getPrettyVersion('illuminate/validation'),
            'vi_validation_compiler' => \Vi\Validation\Compilation\NativeCompiler::COMPILER_VERSION,
            'os' => PHP_OS_FAMILY . ' ' . php_uname('r'),
            'cpu' => $cpu,
            'cpu_count' => is_readable('/proc/cpuinfo') ? substr_count((string) file_get_contents('/proc/cpuinfo'), 'processor') : null,
            'memory_bytes' => $memory,
            'opcache' => is_array($opcache) && ($opcache['opcache_enabled'] ?? false),
            'jit' => is_array($opcache) && (($opcache['jit']['on'] ?? false) === true),
            'sapi' => PHP_SAPI,
            'timestamp' => gmdate('c'),
        ];
    }

    private function timeMs(callable $fn, int $iterations): float
    {
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $fn();
        }

        return (hrtime(true) - $start) / 1e6 / $iterations;
    }
}
