<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Parity\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Validation\Validator as LaravelValidatorContract;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator as LaravelTranslator;
use Illuminate\Validation\Factory as LaravelValidationFactory;
use PHPUnit\Framework\TestCase;
use Vi\Validation\Compilation\NativeCompiler;
use Vi\Validation\Compilation\ValidatorCompiler;
use Vi\Validation\Execution\ValidatorEngine;
use Vi\Validation\Laravel\FastValidatorFactory;
use Vi\Validation\Laravel\FastValidatorWrapper;
use Vi\Validation\Laravel\LaravelRuleParser;
use Vi\Validation\Rules\RuleRegistry;
use Vi\Validation\Schema\SchemaBuilder;
use Vi\Validation\SchemaValidator;

/**
 * Shared harness for running the same rules/data through Laravel's real validator and
 * vi/validation, and comparing normalized outcomes (pass/fail + which fields failed).
 *
 * "Normalized" deliberately does not mean byte-identical error message text: localization
 * and message wording are not part of this parity contract, only which fields fail and why
 * (as reflected by the set of failed attribute names).
 */
abstract class ParityTestCase extends TestCase
{
    private ?RuleRegistry $registry = null;

    /** @var list<string> */
    private array $nativeTempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->nativeTempDirs as $dir) {
            $this->removeDirectory($dir);
        }
        $this->nativeTempDirs = [];

        parent::tearDown();
    }

    protected function registry(): RuleRegistry
    {
        if ($this->registry === null) {
            $this->registry = new RuleRegistry();
            $this->registry->registerBuiltInRules();
        }

        return $this->registry;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $rules
     * @param array<string, string> $messages
     * @param array<string, string> $attributes
     */
    protected function laravel(
        array $data,
        array $rules,
        array $messages = [],
        array $attributes = []
    ): LaravelValidatorContract {
        $translator = new LaravelTranslator(new ArrayLoader(), 'en');
        $container = new Container();
        $factory = new LaravelValidationFactory($translator, $container);

        // Some Laravel rule objects (e.g. Rules\Password, which re-validates via the
        // Validator facade internally) resolve services through the Container/Facade root
        // rather than an injected dependency, so both must point at this validator's own
        // container, with a 'validator' binding resolving back to this same factory.
        $container->instance('validator', $factory);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        return $factory->make($data, $rules, $messages, $attributes);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $rules
     * @param array<string, string> $messages
     * @param array<string, string> $attributes
     */
    protected function fast(
        array $data,
        array $rules,
        array $messages = [],
        array $attributes = []
    ): FastValidatorWrapper {
        $factory = new FastValidatorFactory(['cache' => ['enabled' => false]], $this->registry());

        return $factory->make($data, $rules, $messages, $attributes);
    }

    /**
     * Builds a validator that forces the native-compiled-closure execution path.
     *
     * Returns null when the schema isn't fully native-compilable (per
     * NativeCompiler::canCompile()) so callers can skip the native/engine comparison for
     * rules that intentionally fall back to ValidatorEngine.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $rules
     */
    protected function fastNative(array $data, array $rules): ?FastValidatorWrapper
    {
        $registry = $this->registry();
        $parser = new LaravelRuleParser($registry);
        $builder = new SchemaBuilder();
        $builder->setRulesArray($rules);

        foreach ($rules as $field => $definition) {
            $builder->field((string) $field)->rules(...$parser->parse($definition, (string) $field));
        }

        $schema = $builder->compile();

        $nativeCompiler = new NativeCompiler();
        if (!$nativeCompiler->canCompile($schema)) {
            return null;
        }

        $cachePath = sys_get_temp_dir() . '/vi-validation-parity-native-' . bin2hex(random_bytes(8));
        $this->nativeTempDirs[] = $cachePath;

        $compiler = new ValidatorCompiler(null, false, $cachePath);
        $compiler->writeNative(NativeCompiler::generateKey($schema->getRulesArray()), $schema);

        $schemaValidator = new SchemaValidator($schema, new ValidatorEngine(), $compiler);

        return new FastValidatorWrapper($schemaValidator, $data, $registry);
    }

    /**
     * Assert that Laravel and vi/validation agree on pass/fail and on which fields failed
     * for the given rules/data, and (when the schema is native-compilable) that the native
     * path agrees with the engine path too.
     *
     * @param array<string, mixed> $rules
     * @param array<string, mixed> $data
     * @param array<string, string> $messages
     * @param array<string, string> $attributes
     */
    protected function assertParity(
        array $rules,
        array $data,
        array $messages = [],
        array $attributes = []
    ): void {
        $context = 'rules=' . json_encode($rules) . ' data=' . json_encode($data);

        $laravel = $this->laravel($data, $rules, $messages, $attributes);
        $fast = $this->fast($data, $rules, $messages, $attributes);

        $laravelFails = $laravel->fails();
        $fastFails = $fast->fails();

        $laravelFields = $this->sortedKeys($laravel->errors()->toArray());
        $fastFields = $this->sortedKeys($fast->errors()->toArray());

        self::assertSame($laravelFails, $fastFails, "Overall pass/fail mismatch ($context)");
        self::assertSame($laravelFields, $fastFields, "Failed-field set mismatch ($context)");

        $native = $this->fastNative($data, $rules);
        if ($native !== null) {
            $nativeFails = $native->fails();
            $nativeFields = $this->sortedKeys($native->errors()->toArray());

            self::assertSame($fastFails, $nativeFails, "Engine vs native pass/fail mismatch ($context)");
            self::assertSame($fastFields, $nativeFields, "Engine vs native failed-field mismatch ($context)");
        }
    }

    /**
     * For the rare rule where Laravel and vi/validation use structurally different rule
     * grammars to express the same intent (e.g. Laravel's wildcard `tags.*` => `distinct`
     * vs. vi/validation's whole-array `tags` => `distinct`, since wildcards aren't
     * implemented — see `_attribute_addressing` in the compatibility matrix), asserts only
     * that the two differently-shaped rule sets agree on overall pass/fail.
     *
     * @param array<string, mixed> $laravelRules
     * @param array<string, mixed> $fastRules
     * @param array<string, mixed> $data
     */
    protected function assertEquivalentParity(array $laravelRules, array $fastRules, array $data): void
    {
        $laravel = $this->laravel($data, $laravelRules);
        $fast = $this->fast($data, $fastRules);

        self::assertSame(
            $laravel->fails(),
            $fast->fails(),
            'Overall pass/fail mismatch for equivalent-but-differently-shaped rules: laravel='
                . json_encode($laravelRules) . ' fast=' . json_encode($fastRules) . ' data=' . json_encode($data)
        );
    }

    /**
     * Escape hatch for a documented, currently-intentional Laravel divergence: pins today's
     * actual behavior from both sides instead of requiring them to match, so the test still
     * fails if that behavior accidentally changes. Every call requires a non-empty $reason.
     *
     * @param array<string, mixed> $rules
     * @param array<string, mixed> $data
     * @return array{laravel_fails: bool, fast_fails: bool, laravel_fields: list<string>, fast_fields: list<string>}
     */
    protected function assertDivergence(array $rules, array $data, string $reason): array
    {
        self::assertNotSame('', trim($reason), 'assertDivergence() requires a non-empty $reason.');

        $laravel = $this->laravel($data, $rules);
        $fast = $this->fast($data, $rules);

        return [
            'laravel_fails' => $laravel->fails(),
            'fast_fails' => $fast->fails(),
            'laravel_fields' => $this->sortedKeys($laravel->errors()->toArray()),
            'fast_fields' => $this->sortedKeys($fast->errors()->toArray()),
        ];
    }

    /**
     * @param array<string, mixed> $errors
     * @return list<string>
     */
    private function sortedKeys(array $errors): array
    {
        $keys = array_keys($errors);
        sort($keys);

        return $keys;
    }

    private function removeDirectory(string $dir): void
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
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
