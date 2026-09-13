<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Benchmarks;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator as LaravelTranslator;
use Illuminate\Validation\Factory as LaravelValidationFactory;
use Vi\Validation\Compilation\NativeCompiler;
use Vi\Validation\Compilation\ValidatorCompiler;
use Vi\Validation\Execution\CompiledSchema;
use Vi\Validation\Execution\ValidatorEngine;
use Vi\Validation\Laravel\LaravelRuleParser;
use Vi\Validation\Rules\RuleRegistry;
use Vi\Validation\Schema\SchemaBuilder;
use Vi\Validation\SchemaValidator;

/**
 * Runs one of the four measured variants (see README.md "Benchmarks" section) against a
 * scenario's rows and reports wall-clock trial durations plus, where relevant, schema
 * compilation / native codegen time and a cold-call measurement. Every "loop over $rows and
 * time it" variant funnels through timeLoop() so the warmup/trial/failure-counting logic is
 * defined exactly once.
 */
final class Runner
{
    private RuleRegistry $registry;

    public function __construct()
    {
        $this->registry = new RuleRegistry();
        $this->registry->registerBuiltInRules();
    }

    /**
     * @param array<string, string> $rules
     */
    public function buildCompiledSchema(array $rules): CompiledSchema
    {
        $parser = new LaravelRuleParser($this->registry);
        $builder = new SchemaBuilder();
        $builder->setRulesArray($rules);

        foreach ($rules as $field => $definition) {
            $builder->field((string) $field)->rules(...$parser->parse($definition, (string) $field));
        }

        return $builder->compile();
    }

    private function laravelFactory(): LaravelValidationFactory
    {
        // Standalone bootstrap (no full Laravel app) - see
        // tests/Unit/Parity/Support/ParityTestCase::laravel() for the same pattern used
        // throughout the parity test suite.
        $translator = new LaravelTranslator(new ArrayLoader(), 'en');
        $container = new Container();
        $factory = new LaravelValidationFactory($translator, $container);
        $container->instance('validator', $factory);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        return $factory;
    }

    /**
     * @param array<string, string> $rules
     * @param list<array<string, mixed>> $rows
     * @return array{durations: list<float>, failures: int, compile_time: null, native_gen_time: null}
     */
    public function measureLaravel(array $rules, array $rows, int $trials, int $warmup): array
    {
        $factory = $this->laravelFactory();

        $result = $this->timeLoop(
            static fn (array $row): bool => $factory->make($row, $rules)->passes(),
            $rows,
            $trials,
            $warmup
        );

        return $result + ['compile_time' => null, 'native_gen_time' => null];
    }

    /**
     * Raw ValidatorEngine::validate() called directly, bypassing SchemaValidator's own
     * dispatch/file-exists overhead entirely - isolates rule-execution cost.
     *
     * @param array<string, string> $rules
     * @param list<array<string, mixed>> $rows
     * @return array{durations: list<float>, failures: int, compile_time: float, native_gen_time: null}
     */
    public function measureEngine(array $rules, array $rows, int $trials, int $warmup): array
    {
        $compileStart = hrtime(true);
        $schema = $this->buildCompiledSchema($rules);
        $compileTime = (hrtime(true) - $compileStart) / 1e9;

        $engine = new ValidatorEngine();

        $result = $this->timeLoop(
            static fn (array $row): bool => $engine->validate($schema, $row)->isValid(),
            $rows,
            $trials,
            $warmup
        );

        return $result + ['compile_time' => $compileTime, 'native_gen_time' => null];
    }

    /**
     * Full SchemaValidator public API with native compilation disabled - the real overhead
     * users pay on top of ValidatorEngine when not using compilation.cache_path.
     *
     * @param array<string, string> $rules
     * @param list<array<string, mixed>> $rows
     * @return array{durations: list<float>, failures: int, compile_time: float, native_gen_time: null}
     */
    public function measureCompiled(array $rules, array $rows, int $trials, int $warmup): array
    {
        $compileStart = hrtime(true);
        $schema = $this->buildCompiledSchema($rules);
        $compileTime = (hrtime(true) - $compileStart) / 1e9;

        $validator = new SchemaValidator($schema, new ValidatorEngine());

        $result = $this->timeLoop(
            static fn (array $row): bool => $validator->validate($row)->isValid(),
            $rows,
            $trials,
            $warmup
        );

        if ($validator->isNativeCompiled()) {
            throw new \RuntimeException('measureCompiled() unexpectedly resolved a native validator.');
        }

        return $result + ['compile_time' => $compileTime, 'native_gen_time' => null];
    }

    /**
     * Full SchemaValidator public API with native compilation enabled. Reports schema-compile
     * time, pure NativeCompiler codegen time, codegen+disk-write time, a single cold call
     * (pays file_exists()+require()), and then steady-state warm trials separately.
     *
     * @param array<string, string> $rules
     * @param list<array<string, mixed>> $rows
     * @return array{
     *     supported: bool,
     *     durations?: list<float>,
     *     failures?: int,
     *     compile_time?: float,
     *     native_gen_time?: float,
     *     native_write_time?: float,
     *     cold_time?: float
     * }
     */
    public function measureNative(array $rules, array $rows, int $trials, int $warmup, string $cacheDir): array
    {
        $compileStart = hrtime(true);
        $schema = $this->buildCompiledSchema($rules);
        $compileTime = (hrtime(true) - $compileStart) / 1e9;

        $nativeCompiler = new NativeCompiler();
        if (!$nativeCompiler->canCompile($schema)) {
            return ['supported' => false];
        }

        $genStart = hrtime(true);
        $nativeCompiler->compile($schema);
        $nativeGenTime = (hrtime(true) - $genStart) / 1e9;

        $compiler = new ValidatorCompiler(null, false, $cacheDir);
        $writeStart = hrtime(true);
        $compiler->writeNative(NativeCompiler::generateKey($schema->getRulesArray()), $schema);
        $nativeWriteTime = (hrtime(true) - $writeStart) / 1e9;

        $validator = new SchemaValidator($schema, new ValidatorEngine(), $compiler);

        $coldStart = hrtime(true);
        $validator->validate($rows[0]);
        $coldTime = (hrtime(true) - $coldStart) / 1e9;

        if (!$validator->isNativeCompiled()) {
            throw new \RuntimeException(
                'measureNative(): NativeCompiler::canCompile() was true but SchemaValidator did not '
                . 'resolve the native path - the schema and NativeCompiler disagree on compilability.'
            );
        }

        $result = $this->timeLoop(
            static fn (array $row): bool => $validator->validate($row)->isValid(),
            $rows,
            $trials,
            $warmup
        );

        return $result + [
            'supported' => true,
            'compile_time' => $compileTime,
            'native_gen_time' => $nativeGenTime,
            'native_write_time' => $nativeWriteTime,
            'cold_time' => $coldTime,
        ];
    }

    /**
     * @param \Closure(array<string, mixed>): bool $isValid
     * @param list<array<string, mixed>> $rows
     * @return array{durations: list<float>, failures: int}
     */
    private function timeLoop(\Closure $isValid, array $rows, int $trials, int $warmup): array
    {
        $run = static function () use ($isValid, $rows): array {
            $start = hrtime(true);
            $failures = 0;
            foreach ($rows as $row) {
                if (!$isValid($row)) {
                    $failures++;
                }
            }

            return [(hrtime(true) - $start) / 1e9, $failures];
        };

        for ($i = 0; $i < $warmup; $i++) {
            $run();
        }

        $durations = [];
        $failures = 0;
        for ($i = 0; $i < $trials; $i++) {
            [$duration, $failures] = $run();
            $durations[] = $duration;
        }

        return ['durations' => $durations, 'failures' => $failures];
    }
}
