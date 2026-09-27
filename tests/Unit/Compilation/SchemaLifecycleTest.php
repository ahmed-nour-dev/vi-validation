<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Compilation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Vi\Validation\Compilation\NativeArtifactRepository;
use Vi\Validation\Compilation\ValidatorCompiler;
use Vi\Validation\Laravel\FastValidatorFactory;
use Vi\Validation\Schema\SchemaBuilder;
use Vi\Validation\SchemaValidator;
use Vi\Validation\Validator;

/**
 * Build -> compile -> generate/persist artifact -> load -> execute, as separate steps.
 */
#[Group('native')]
final class SchemaLifecycleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        NativeArtifactRepository::flushMemory();
        $this->dir = sys_get_temp_dir() . '/vi-validation-lifecycle-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        NativeArtifactRepository::flushMemory();

        if (!is_dir($this->dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return [
            ['email' => 'a@example.com', 'age' => 30],
            ['email' => 'nope', 'age' => 30],
            ['email' => 'a@example.com', 'age' => '12'],
            ['age' => 99],
        ];
    }

    public function testExplicitDeployTimeWorkflow(): void
    {
        // Deploy step: build + compile + persist, in a separate "process".
        $schema = Validator::fromRules(['email' => 'required|email', 'age' => 'required|integer|min:18']);
        $deployCompiler = new ValidatorCompiler(null, false, $this->dir, 'secret');
        $path = $deployCompiler->writeNativeFor($schema);
        $this->assertNotNull($path);
        NativeArtifactRepository::flushMemory();

        // Request/job: rebuild the same schema, load the existing artifact, execute.
        $runtimeSchema = Validator::fromRules(['email' => 'required|email', 'age' => 'required|integer|min:18']);
        $this->assertSame($schema->fingerprint()->artifactKey, $runtimeSchema->fingerprint()->artifactKey);

        $validator = new SchemaValidator($runtimeSchema, null, new ValidatorCompiler(null, false, $this->dir, 'secret'));
        $this->assertTrue($validator->warm(), 'existing artifact is loaded without precompile enabled');

        $engine = new SchemaValidator($runtimeSchema);
        foreach ($this->rows() as $row) {
            $this->assertSame($engine->validate($row)->errors(), $validator->validate($row)->errors());
        }
    }

    public function testWithoutPrecompileNothingIsGeneratedAtRuntime(): void
    {
        $schema = Validator::fromRules(['email' => 'required|email']);
        $validator = new SchemaValidator($schema, null, new ValidatorCompiler(null, false, $this->dir));

        $this->assertFalse($validator->warm());
        $this->assertTrue($validator->validate(['email' => 'a@example.com'])->isValid());
        $this->assertSame([], glob($this->dir . '/native/*.php') ?: []);
    }

    public function testBuildWithPrecompileGeneratesOnFirstUse(): void
    {
        $validator = SchemaValidator::build(
            static fn (SchemaBuilder $s) => $s->field('email')->required()->email(),
            ['compilation' => ['precompile' => true, 'cache_path' => $this->dir]]
        );

        $this->assertFalse($validator->validate(['email' => 'bad'])->isValid());
        $this->assertTrue($validator->usesNative(), 'first validate() generated and loaded the artifact');
        $this->assertCount(1, glob($this->dir . '/native/*.php') ?: []);
        $this->assertTrue($validator->validate(['email' => 'a@example.com'])->isValid());
    }

    public function testWarmOnANonCompilableSchemaFallsBackToEngine(): void
    {
        $validator = SchemaValidator::build(
            static fn (SchemaBuilder $s) => $s->field('role')->required()->in('admin', 'user'),
            ['compilation' => ['precompile' => true, 'cache_path' => $this->dir]]
        );

        $this->assertFalse($validator->warm());
        $this->assertFalse($validator->validate(['role' => 'root'])->isValid());
        $this->assertTrue($validator->validate(['role' => 'admin'])->isValid());
    }

    public function testFactoryPrecompileThenMakeUsesArtifact(): void
    {
        $config = ['compilation' => ['precompile' => false, 'cache_path' => $this->dir]];
        $rules = ['email' => 'required|email', 'name' => 'required|string|max:5'];

        $this->assertNotNull((new FastValidatorFactory($config))->precompile($rules));
        NativeArtifactRepository::flushMemory();

        $wrapper = (new FastValidatorFactory($config))->make(['email' => 'x', 'name' => 'toolong'], $rules);

        $this->assertTrue($wrapper->getSchemaValidator()->usesNative());
        $this->assertTrue($wrapper->fails());
        $this->assertSame(['email', 'name'], array_keys($wrapper->errors()->toArray()));
    }

    public function testFactoryCompileOnFirstUseKeepsCustomMessagesOnNativePath(): void
    {
        $factory = new FastValidatorFactory(['compilation' => ['precompile' => true, 'cache_path' => $this->dir]]);

        $wrapper = $factory->make(
            ['email' => 'bad'],
            ['email' => 'required|email'],
            ['email.email' => 'Give us a real :attribute'],
            ['email' => 'e-mail address']
        );

        $this->assertTrue($wrapper->fails());
        $this->assertTrue($wrapper->getSchemaValidator()->usesNative());
        $this->assertSame('Give us a real e-mail address', $wrapper->errors()->first('email'));
    }

    public function testFactoryClearAndPrune(): void
    {
        $factory = new FastValidatorFactory(['compilation' => ['cache_path' => $this->dir]]);
        $factory->precompile(['a' => 'required']);
        $factory->precompile(['b' => 'required']);

        $this->assertSame(0, $factory->pruneCompiled());
        $this->assertSame(2, $factory->clearCompiled());
        $this->assertNull((new FastValidatorFactory())->precompile(['a' => 'required']), 'no cache path, no artifact');
    }

    public function testSometimesRebuildKeepsMessagesAndNumericContext(): void
    {
        $wrapper = (new FastValidatorFactory())->make(
            ['age' => '50'],
            ['age' => 'required|integer'],
            ['age.max' => 'Too old: :attribute']
        );

        $wrapper->sometimes('age', 'max:10', static fn (): bool => true);

        // '50' with integer|max:10 must be compared numerically (Laravel semantics), and the
        // custom message must survive the rebuild.
        $this->assertTrue($wrapper->fails());
        $this->assertSame('Too old: age', $wrapper->errors()->first('age'));
    }

    public function testNullableAndBailFieldsAreNativeAndMatchTheEngine(): void
    {
        $rules = [
            'age' => 'nullable|integer|min:18',
            'code' => 'bail|required|string|min:3|alpha',
            'nick' => 'bail|nullable|string|max:4|alpha_num',
        ];
        $schema = Validator::fromRules($rules);
        $native = new SchemaValidator($schema, null, new ValidatorCompiler(null, true, $this->dir));
        $engine = new SchemaValidator($schema);

        $this->assertTrue($native->warm(), 'nullable/bail must not block native compilation');

        $cases = [
            [],
            ['age' => null, 'code' => 'abc', 'nick' => null],
            ['age' => 12, 'code' => 'a1', 'nick' => 'too_long!'],
            ['age' => 'x', 'code' => 5, 'nick' => ''],
            ['age' => '20', 'code' => 'ab', 'nick' => 'abcd'],
            ['code' => null, 'nick' => ['x']],
        ];

        foreach ($cases as $row) {
            $this->assertSame($engine->validate($row)->errors(), $native->validate($row)->errors(), json_encode($row) ?: '');
        }
    }
}
