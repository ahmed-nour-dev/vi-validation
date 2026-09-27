<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Compilation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Vi\Validation\Compilation\NativeCompiler;
use Vi\Validation\Compilation\SchemaFingerprint;
use Vi\Validation\Compilation\ValidatorCompiler;
use Vi\Validation\Execution\CompiledSchema;
use Vi\Validation\Execution\ValidatorEngine;
use Vi\Validation\Laravel\FastValidatorFactory;
use Vi\Validation\Laravel\LaravelRuleParser;
use Vi\Validation\Rules\RuleRegistry;
use Vi\Validation\Schema\SchemaBuilder;
use Vi\Validation\SchemaValidator;
use Vi\Validation\Validator;

#[Group('native')]
final class SchemaFingerprintTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
    }

    /**
     * @param array<string, mixed> $rules
     */
    private function fromLaravelRules(array $rules): CompiledSchema
    {
        $registry = new RuleRegistry();
        $registry->registerBuiltInRules();
        $parser = new LaravelRuleParser($registry);
        $builder = new SchemaBuilder();
        $builder->setRulesArray($rules);

        foreach ($rules as $field => $definition) {
            $builder->field((string) $field)->rules(...$parser->parse($definition, (string) $field));
        }

        return $builder->compile();
    }

    public function testFingerprintIsDeterministicAcrossCompilations(): void
    {
        $a = $this->fromLaravelRules(['email' => 'required|email', 'age' => 'nullable|integer|min:18']);
        $b = $this->fromLaravelRules(['email' => 'required|email', 'age' => 'nullable|integer|min:18']);

        $this->assertNotSame($a, $b);
        $this->assertSame($a->fingerprint()->schemaHash, $b->fingerprint()->schemaHash);
        $this->assertSame($a->fingerprint()->artifactKey, $b->fingerprint()->artifactKey);
        $this->assertTrue($a->fingerprint()->stable);
        $this->assertSame([], $a->fingerprint()->unstableReasons);
    }

    public function testFingerprintIsMemoized(): void
    {
        $schema = $this->fromLaravelRules(['email' => 'required|email']);

        $this->assertSame($schema->fingerprint(), $schema->fingerprint());
    }

    public function testInputFormatDoesNotAffectFingerprint(): void
    {
        $pipe = $this->fromLaravelRules(['name' => 'required|string|max:10', 'email' => 'nullable|email']);
        $array = $this->fromLaravelRules(['name' => ['required', 'string', 'max:10'], 'email' => ['nullable', 'email']]);
        // The rule-string parser always yields numeric params as floats, so mirror that here.
        $fluent = Validator::schema()
            ->field('name')->required()->string()->max(10.0)
            ->field('email')->rules(new \Vi\Validation\Rules\NullableRule())->email()
            ->compile();

        $this->assertSame($pipe->fingerprint()->schemaHash, $array->fingerprint()->schemaHash);
        $this->assertSame($pipe->fingerprint()->schemaHash, $fluent->fingerprint()->schemaHash);
    }

    public function testIntAndFloatParametersAreConservativelyDistinct(): void
    {
        // Some rules compare parameters strictly, so int 10 and float 10.0 are never assumed
        // equivalent: at worst that costs a cache miss, never a wrong artifact.
        $int = Validator::schema()->field('name')->max(10)->compile();
        $float = Validator::schema()->field('name')->max(10.0)->compile();

        $this->assertNotSame($int->fingerprint()->schemaHash, $float->fingerprint()->schemaHash);
    }

    public function testFingerprintChangesWhenSemanticsChange(): void
    {
        $base = $this->fromLaravelRules(['name' => 'required|string|max:10']);

        $variants = [
            'different param' => ['name' => 'required|string|max:11'],
            'param type' => ['name' => 'required|string|max:10.5'],
            'extra rule' => ['name' => 'required|string|max:10|alpha'],
            'missing rule' => ['name' => 'string|max:10'],
            'nullable' => ['name' => 'nullable|required|string|max:10'],
            'bail' => ['name' => 'bail|required|string|max:10'],
            'sometimes' => ['name' => 'sometimes|required|string|max:10'],
            'exclude' => ['name' => 'exclude|required|string|max:10'],
            'field name' => ['title' => 'required|string|max:10'],
            'numeric context' => ['name' => 'required|numeric|max:10'],
            'extra field' => ['name' => 'required|string|max:10', 'x' => 'string'],
        ];

        $seen = [$base->fingerprint()->schemaHash => 'base'];
        foreach ($variants as $label => $rules) {
            $hash = $this->fromLaravelRules($rules)->fingerprint()->schemaHash;
            $this->assertArrayNotHasKey($hash, $seen, "'{$label}' collided with '" . ($seen[$hash] ?? '') . "'");
            $seen[$hash] = $label;
        }
    }

    public function testRuleParameterValuesAreTypeTagged(): void
    {
        $in = $this->fromLaravelRules(['a' => ['in:1,2']]);
        $inOther = $this->fromLaravelRules(['a' => ['in:12']]);

        $this->assertNotSame($in->fingerprint()->schemaHash, $inOther->fingerprint()->schemaHash);
    }

    public function testFluentSchemasNoLongerShareOneKey(): void
    {
        // Regression: fluent schemas have an empty rules array, so the old
        // NativeCompiler::generateKey($schema->getRulesArray()) gave all of them the same key.
        $a = Validator::schema()->field('name')->required()->string()->compile();
        $b = Validator::schema()->field('age')->required()->integer()->compile();

        $this->assertSame(
            NativeCompiler::generateKey($a->getRulesArray()),
            NativeCompiler::generateKey($b->getRulesArray()),
            'precondition: the legacy key collides'
        );
        $this->assertNotSame($a->fingerprint()->artifactKey, $b->fingerprint()->artifactKey);
    }

    public function testNativeArtifactOfOneFluentSchemaIsNeverUsedForAnother(): void
    {
        $cacheDir = $this->tempDir();
        $compiler = new ValidatorCompiler(null, false, $cacheDir);

        $lenient = Validator::schema()->field('name')->string()->compile();
        $strict = Validator::schema()->field('age')->required()->integer()->compile();

        $this->assertNotNull($compiler->writeNativeFor($lenient));

        $validator = new SchemaValidator($strict, new ValidatorEngine(), $compiler);

        $this->assertFalse($validator->validate([])->isValid(), 'strict schema must not run the lenient artifact');
    }

    public function testArtifactKeyEncodesCompilerAndRuntimeVersions(): void
    {
        $fingerprint = $this->fromLaravelRules(['a' => 'required'])->fingerprint();

        $expected = hash(
            'sha256',
            $fingerprint->schemaHash . '|' . SchemaFingerprint::FORMAT_VERSION . '|'
            . NativeCompiler::COMPILER_VERSION . '|' . PHP_VERSION_ID
        );

        $this->assertSame($expected, $fingerprint->artifactKey);
        $this->assertNotSame($fingerprint->schemaHash, $fingerprint->artifactKey);
    }

    public function testClosureRulesProduceUnstableNonCollidingFingerprints(): void
    {
        $closure = static function (string $attribute, mixed $value, \Closure $fail): void {
        };

        $a = $this->fromLaravelRules(['a' => ['required', $closure]]);
        $b = $this->fromLaravelRules(['a' => ['required', $closure]]);

        $this->assertFalse($a->fingerprint()->stable);
        $this->assertNotSame([], $a->fingerprint()->unstableReasons);
        $this->assertNotSame(
            $a->fingerprint()->schemaHash,
            $b->fingerprint()->schemaHash,
            'unstable schemas must never share an identity'
        );
    }

    public function testConditionalWhenRulesAreUnstable(): void
    {
        $schema = Validator::schema()
            ->field('a')->when(static fn (): bool => true, static function ($f): void {
                $f->required();
            })
            ->compile();

        $this->assertFalse($schema->fingerprint()->stable);
    }

    public function testUnstableSchemasNeverGetANativeArtifact(): void
    {
        $compiler = new ValidatorCompiler(null, false, $this->tempDir());
        $schema = $this->fromLaravelRules(['a' => ['required', static function ($attr, $value, $fail): void {
        }]]);

        $this->assertNull($compiler->writeNativeFor($schema));
    }

    public function testFactoryDoesNotReuseCachedSchemaForADifferentClosure(): void
    {
        // Regression: the factory cache keyed closures by spl_object_id(), which PHP reuses
        // once the previous closure is freed, so a new closure could hit a stale schema.
        $factory = new FastValidatorFactory();

        for ($i = 0; $i < 20; $i++) {
            $shouldFail = $i % 2 === 0;
            $rule = static function (string $attribute, mixed $value, \Closure $fail) use ($shouldFail): void {
                if ($shouldFail) {
                    $fail('nope');
                }
            };

            $this->assertSame($shouldFail, $factory->make(['a' => 'x'], ['a' => [$rule]])->fails(), "iteration {$i}");
            unset($rule);
        }
    }

    public function testFactoryCacheStillHitsForPlainStringRules(): void
    {
        $factory = new FastValidatorFactory();
        $factory->make(['a' => 'x'], ['a' => 'required|string']);
        $factory->make(['a' => 'y'], ['a' => 'required|string']);

        $cache = $factory->getCache();
        $this->assertNotNull($cache);
        $this->assertTrue($factory->make(['a' => 'x'], ['a' => 'required|string'])->passes());
        $this->assertFalse($factory->make(['a' => 'x'], ['a' => 'required|integer'])->passes());
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/vi-validation-fingerprint-' . bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
