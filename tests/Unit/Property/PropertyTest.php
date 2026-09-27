<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Property;

use Illuminate\Container\Container;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as IlluminateFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Vi\Validation\Compilation\NativeArtifactRepository;
use Vi\Validation\Compilation\NativeCompiler;
use Vi\Validation\Execution\CompiledSchema;
use Vi\Validation\Execution\ErrorMode;
use Vi\Validation\Execution\ValidationResult;
use Vi\Validation\Execution\ValidatorEngine;
use Vi\Validation\SchemaValidator;
use Vi\Validation\Validator;

/**
 * Property-based tests: invariants checked over randomly generated schemas and data.
 *
 * Seeded and reproducible - every failure message includes the seed and the command to
 * replay it (see Fuzzer). Iteration counts are kept CI-friendly; raise them locally with
 * VI_FUZZ_ITERATIONS.
 */
#[Group('property')]
final class PropertyTest extends TestCase
{
    private const FIELDS = ['a', 'b', 'c', 'p.q', 'p.r.s', 'x.y'];

    private string $dir;

    protected function setUp(): void
    {
        NativeArtifactRepository::flushMemory();
        $this->dir = sys_get_temp_dir() . '/vi-validation-property-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        NativeArtifactRepository::flushMemory();
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->dir);
    }

    /**
     * A random Laravel-style rules array using only natively compilable rules.
     *
     * @return array<string, string>
     */
    private function nativeRules(Fuzzer $f): array
    {
        $pool = ['required', 'string', 'integer', 'numeric', 'boolean', 'array', 'email', 'url', 'ip', 'ipv4', 'ipv6', 'json', 'alpha', 'alpha_num', 'alpha_dash', 'min', 'max'];
        $rules = [];

        foreach (self::FIELDS as $field) {
            if (!$f->bool(60)) {
                continue;
            }

            $parts = [];
            if ($f->bool(20)) {
                $parts[] = 'bail';
            }
            if ($f->bool(25)) {
                $parts[] = 'nullable';
            }
            if ($f->bool(15)) {
                $parts[] = 'sometimes';
            }
            for ($i = 0, $n = $f->int(1, 4); $i < $n; $i++) {
                $rule = $f->pick($pool);
                $parts[] = in_array($rule, ['min', 'max'], true) ? $rule . ':' . $f->pick(['0', '1', '3', '18', '2.5', '-1']) : $rule;
            }
            $rules[$field] = implode('|', array_unique($parts));
        }

        return $rules === [] ? ['a' => 'required'] : $rules;
    }

    /**
     * @param array<string, string> $rules
     * @return array<string, mixed>
     */
    private function row(Fuzzer $f, array $rules): array
    {
        $row = [];
        foreach (array_keys($rules) as $field) {
            if ($f->bool(15)) {
                continue; // absent
            }
            $segments = explode('.', $field);
            $ref = &$row;
            foreach ($segments as $i => $segment) {
                if ($i === count($segments) - 1) {
                    $ref[$segment] = $f->value();
                } else {
                    if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                        $ref[$segment] = $f->bool(90) ? [] : $f->value();
                    }
                    if (!is_array($ref[$segment])) {
                        break;
                    }
                    $ref = &$ref[$segment];
                }
            }
            unset($ref);
        }
        if ($f->bool(20)) {
            $row['unrelated'] = $f->value();
        }

        return $row;
    }

    private function nativeValidator(CompiledSchema $schema, ?ValidatorEngine $engine = null): SchemaValidator
    {
        $repository = new NativeArtifactRepository($this->dir, new NativeCompiler());
        $closure = $repository->load((string) basename((string) $repository->store($schema), '.php'));
        $this->assertNotNull($closure, 'schema should be natively compilable');

        $validator = new SchemaValidator($schema, $engine ?? new ValidatorEngine());
        $native = new \ReflectionProperty(SchemaValidator::class, 'cachedNativeValidator');
        $native->setAccessible(true);
        $native->setValue($validator, new \Vi\Validation\Execution\NativeValidator($closure));

        return $validator;
    }

    /**
     * Run $fn converting PHP warnings/notices into exceptions (a warning from a rule is a bug).
     */
    private function strict(callable $fn): mixed
    {
        set_error_handler(static function (int $no, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $no, $file, $line);
        }, E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private static function shape(ValidationResult $result): array
    {
        return array_map(static fn (array $e): array => array_column($e, 'rule'), $result->errors());
    }

    private static function describe(mixed $value): string
    {
        return str_replace("\n", ' ', var_export($value, true));
    }

    public function testNativeAndEngineAgreeOnEverySchemaAndRow(): void
    {
        $f = new Fuzzer();
        $iterations = Fuzzer::iterations(150);

        for ($i = 0; $i < $iterations; $i++) {
            $rules = $this->nativeRules($f);
            $schema = Validator::fromRules($rules);
            $engine = new SchemaValidator($schema);
            $native = $this->nativeValidator($schema);

            for ($r = 0; $r < 8; $r++) {
                $row = $this->row($f, $rules);
                $e = $this->strict(static fn () => $engine->validate($row));
                $n = $this->strict(static fn () => $native->validate($row));

                $this->assertSame(
                    self::shape($e),
                    self::shape($n),
                    "native/engine mismatch\nrules=" . json_encode($rules) . "\nrow=" . self::describe($row) . "\n" . $f->reproduce(__FUNCTION__)
                );
                $this->assertSame($e->errors(), $n->errors(), $f->reproduce(__FUNCTION__));
            }
        }
    }

    public function testReusedValidatorEqualsFreshValidator(): void
    {
        $f = new Fuzzer();
        $iterations = Fuzzer::iterations(60);

        $pool = [];
        for ($i = 0; $i < 5; $i++) {
            $rules = $this->nativeRules($f);
            $pool[] = [$rules, Validator::fromRules($rules)];
        }
        $shared = new ValidatorEngine();

        for ($i = 0; $i < $iterations * 10; $i++) {
            [$rules, $schema] = $f->pick($pool);
            $row = $this->row($f, $rules);

            $this->assertSame(
                (new ValidatorEngine())->validate($schema, $row)->errors(),
                $shared->validate($schema, $row)->errors(),
                'rules=' . json_encode($rules) . ' row=' . self::describe($row) . "\n" . $f->reproduce(__FUNCTION__)
            );
        }
    }

    public function testValidationNeverMutatesInputAndValidatedNeverMutatesData(): void
    {
        $f = new Fuzzer();

        for ($i = 0; $i < Fuzzer::iterations(100); $i++) {
            $rules = $this->nativeRules($f);
            if ($f->bool(30)) {
                $rules[$f->pick(self::FIELDS)] = $f->pick(['exclude', 'exclude_if:a,1', 'exclude_without:b', 'exclude_with:c']);
            }
            $row = $this->row($f, $rules);
            $before = serialize($row);

            $result = (new SchemaValidator(Validator::fromRules($rules)))->validate($row);

            $this->assertSame($before, serialize($row), 'input mutated ' . $f->reproduce(__FUNCTION__));
            $this->assertSame($before, serialize($result->data()), 'data() differs from input ' . $f->reproduce(__FUNCTION__));
            $result->validated();
            $this->assertSame($before, serialize($result->data()), 'validated() mutated data() ' . $f->reproduce(__FUNCTION__));
        }
    }

    public function testMaxErrorsAlwaysBoundsTheErrorCount(): void
    {
        $f = new Fuzzer();

        for ($i = 0; $i < Fuzzer::iterations(100); $i++) {
            $rules = $this->nativeRules($f);
            $schema = Validator::fromRules($rules);
            $max = $f->int(1, 4);
            $mode = $f->pick(ErrorMode::cases());
            $row = $this->row($f, $rules);

            foreach ([false, true] as $useNative) {
                $engine = new ValidatorEngine(null, false, $max, $mode);
                $validator = $useNative ? $this->nativeValidator($schema, $engine) : new SchemaValidator($schema, $engine);
                $count = $validator->validate($row)->errorCount();

                $this->assertLessThanOrEqual($max, $count, "max_errors={$max} mode={$mode->value} " . $f->reproduce(__FUNCTION__));
            }
        }
    }

    public function testStreamingAgreesWithOneAtATime(): void
    {
        $f = new Fuzzer();

        for ($i = 0; $i < Fuzzer::iterations(30); $i++) {
            $rules = $this->nativeRules($f);
            $validator = new SchemaValidator(Validator::fromRules($rules));
            $rows = [];
            for ($r = 0; $r < 20; $r++) {
                $rows[] = $this->row($f, $rules);
            }

            $single = array_map(static fn (array $row): array => $validator->validate($row)->errors(), $rows);
            $streamed = array_map(static fn (ValidationResult $r): array => $r->errors(), iterator_to_array($validator->stream($rows)));
            $failures = array_map(static fn (ValidationResult $r): array => $r->errors(), iterator_to_array($validator->failures($rows)));

            $this->assertSame($single, $streamed, $f->reproduce(__FUNCTION__));
            $this->assertSame(array_filter($single, static fn (array $e): bool => $e !== []), $failures, $f->reproduce(__FUNCTION__));
        }
    }

    public function testArbitraryValuesNeverThrowUnlessLaravelDoes(): void
    {
        $f = new Fuzzer();
        $laravel = new IlluminateFactory(new Translator(new ArrayLoader(), 'en'), new Container());
        $pool = [
            'required', 'nullable', 'string', 'integer', 'numeric', 'boolean', 'array', 'list', 'email', 'url',
            'ip', 'json', 'alpha', 'alpha_num', 'alpha_dash', 'ascii', 'lowercase', 'uppercase', 'uuid', 'ulid',
            'min:2', 'max:5', 'size:3', 'between:1,4', 'digits:3', 'digits_between:1,3', 'decimal:0,2',
            'in:a,1,true', 'not_in:x', 'starts_with:a', 'ends_with:z', 'doesnt_start_with:q', 'regex:/^a/',
            'not_regex:/z$/', 'date', 'date_format:Y-m-d', 'after:2020-01-01', 'before:2030-01-01', 'timezone',
            'mac_address', 'multiple_of:3', 'filled', 'present', 'accepted', 'declined', 'distinct', 'confirmed',
            'same:b', 'different:b', 'gt:b', 'lt:b', 'required_with:b', 'prohibited_unless:b,1', 'hex_color_unused',
        ];
        $pool = array_values(array_filter($pool, static fn (string $r): bool => $r !== 'hex_color_unused'));

        for ($i = 0; $i < Fuzzer::iterations(300); $i++) {
            $rule = $f->pick($pool);
            if ($rule === 'list' && !method_exists(\Illuminate\Validation\Validator::class, 'validateList')) {
                continue;
            }
            $rules = ['a' => $rule, 'b' => 'nullable'];
            $data = ['a' => $f->value(), 'b' => $f->value(), 'a_confirmation' => $f->value()];

            $laravelThrew = null;
            // Laravel's own notices/deprecations on odd input are not what's under test here.
            set_error_handler(static fn (): bool => true);
            try {
                $laravel->make($data, $rules)->passes();
            } catch (\Throwable $e) {
                $laravelThrew = $e;
            } finally {
                restore_error_handler();
            }

            try {
                $this->strict(static fn () => (new SchemaValidator(Validator::fromRules($rules)))->validate($data));
            } catch (\Throwable $e) {
                if ($laravelThrew === null) {
                    $this->fail(sprintf(
                        "vi/validation threw %s (%s) where Laravel did not\nrule=%s value=%s\n%s",
                        get_class($e),
                        $e->getMessage(),
                        $rule,
                        self::describe($data['a']),
                        $f->reproduce(__FUNCTION__)
                    ));
                }
            }
        }

        $this->addToAssertionCount(1);
    }
}
