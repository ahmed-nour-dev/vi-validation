<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Compilation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Vi\Validation\Compilation\NativeArtifactException;
use Vi\Validation\Compilation\NativeArtifactRepository;
use Vi\Validation\Compilation\NativeCompilationContext;
use Vi\Validation\Compilation\ValidatorCompiler;
use Vi\Validation\Execution\CompiledSchema;
use Vi\Validation\Execution\ValidationContext;
use Vi\Validation\Execution\ValidatorEngine;
use Vi\Validation\Rules\NativeCompilableInterface;
use Vi\Validation\Rules\RuleInterface;
use Vi\Validation\SchemaValidator;
use Vi\Validation\Validator;

#[Group('native')]
final class NativeArtifactRepositoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        NativeArtifactRepository::flushMemory();
        $this->dir = sys_get_temp_dir() . '/vi-validation-artifacts-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        NativeArtifactRepository::flushMemory();
        $this->removeDirectory($this->dir);
        $this->removeDirectory($this->dir . '-compiler');
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

    private function schema(): CompiledSchema
    {
        return Validator::schema()
            ->field('name')->required()->string()->max(10)
            ->field('age')->required()->integer()->min(18)
            ->compile();
    }

    public function testStoreWritesVerifiedArtifactThatLoads(): void
    {
        $repo = new NativeArtifactRepository($this->dir);
        $schema = $this->schema();

        $path = $repo->store($schema);

        $this->assertSame($repo->pathFor($repo->keyFor($schema)), $path);
        $this->assertFileExists($path);
        $this->assertStringContainsString('// vi-validation-native format=1&key=' . $repo->keyFor($schema), (string) file_get_contents($path));

        $closure = $repo->loadFor($schema);
        $this->assertNotNull($closure);
        $this->assertTrue($closure(['name' => 'Bob', 'age' => 30])['valid']);
        $this->assertFalse($closure(['name' => 'Bob', 'age' => 3])['valid']);
    }

    public function testStoreIsIdempotentAndLeavesNoTempFiles(): void
    {
        $repo = new NativeArtifactRepository($this->dir);
        $schema = $this->schema();

        $path = (string) $repo->store($schema);
        $contents = file_get_contents($path);
        $this->assertSame($path, $repo->store($schema));
        $this->assertSame($contents, file_get_contents($path), 'a valid artifact is never regenerated');

        $this->assertSame([], glob($this->dir . '/.*.tmp') ?: []);
    }

    public function testTruncatedArtifactIsDiscardedAndEngineFallbackIsCorrect(): void
    {
        $compiler = new ValidatorCompiler(null, false, $this->dir);
        $repo = $compiler->nativeRepository();
        $this->assertNotNull($repo);

        $schema = $this->schema();
        $path = (string) $compiler->writeNativeFor($schema);
        file_put_contents($path, substr((string) file_get_contents($path), 0, 200));

        $validator = new SchemaValidator($schema, new ValidatorEngine(), $compiler);

        $this->assertFalse($validator->usesNative());
        $this->assertFileDoesNotExist($path, 'corrupt artifact must be deleted');
        $this->assertTrue($validator->validate(['name' => 'Bob', 'age' => 30])->isValid());
        $this->assertFalse($validator->validate(['name' => 'Bob', 'age' => 3])->isValid());

        // ...and is regenerated on the next store.
        $this->assertSame($path, $compiler->writeNativeFor($schema));
        $this->assertNotNull($repo->loadFor($schema));
    }

    public function testTamperedBodyFailsChecksum(): void
    {
        $repo = new NativeArtifactRepository($this->dir);
        $schema = $this->schema();
        $path = (string) $repo->store($schema);

        // Same length, different logic: make every row pass.
        $code = (string) file_get_contents($path);
        file_put_contents($path, str_replace("'valid' => !\$hasErrors", "'valid' => true || \$hasErrors", $code));

        $this->assertNull($repo->loadFor($schema));
        $this->assertFileDoesNotExist($path);
    }

    public function testArtifactCopiedUnderAnotherKeyIsRejected(): void
    {
        $repo = new NativeArtifactRepository($this->dir);
        $lenient = Validator::schema()->field('name')->string()->compile();
        $strict = $this->schema();

        $lenientPath = (string) $repo->store($lenient);
        copy($lenientPath, $repo->pathFor($repo->keyFor($strict)));

        $this->assertNull($repo->loadFor($strict));
    }

    public function testArtifactNotReturningAValidClosureIsRejected(): void
    {
        $repo = new NativeArtifactRepository($this->dir);
        $key = str_repeat('a', 64);
        mkdir($this->dir, 0755, true);

        foreach (["return 42;\n", "return function (array \$data) { return ['nope' => 1]; };\n", "throw new \\RuntimeException('x');\n"] as $body) {
            $header = '// vi-validation-native ' . http_build_query([
                'format' => NativeArtifactRepository::ARTIFACT_FORMAT,
                'key' => $key,
                'compiler' => \Vi\Validation\Compilation\NativeCompiler::COMPILER_VERSION,
                'php' => PHP_VERSION_ID,
                'sha256' => hash('sha256', $body),
            ]);
            file_put_contents($repo->pathFor($key), "<?php\n{$header}\n{$body}");

            $this->assertNull($repo->load($key), $body);
            $this->assertFileDoesNotExist($repo->pathFor($key));
        }
    }

    public function testFileWithoutHeaderIsRejectedWithoutBeingExecuted(): void
    {
        $repo = new NativeArtifactRepository($this->dir);
        $key = str_repeat('b', 64);
        mkdir($this->dir, 0755, true);
        $marker = $this->dir . '/executed';
        file_put_contents($repo->pathFor($key), "<?php\nfile_put_contents(" . var_export($marker, true) . ", '1');\nreturn function (array \$d) { return ['valid' => true, 'errors' => [], 'excluded_fields' => []]; };\n");

        $this->assertNull($repo->load($key));
        $this->assertFileDoesNotExist($marker);
    }

    public function testInvalidGeneratedCodeIsNeverPersisted(): void
    {
        $repo = new NativeArtifactRepository($this->dir);
        $schema = Validator::schema()->field('a')->rules(new BrokenNativeRule())->compile();

        try {
            $repo->store($schema);
            $this->fail('Expected NativeArtifactException');
        } catch (NativeArtifactException $e) {
            $this->assertStringContainsString('invalid PHP', $e->getMessage());
        }

        $this->assertFileDoesNotExist($repo->pathFor($repo->keyFor($schema)));
        $this->assertSame([], glob($this->dir . '/.*.tmp') ?: []);

        // Through ValidatorCompiler the failure is swallowed and validation uses the engine.
        $compiler = new ValidatorCompiler(null, false, $this->dir . '-compiler');
        $this->assertNull($compiler->writeNativeFor($schema));
        $validator = new SchemaValidator($schema, new ValidatorEngine(), $compiler);
        $this->assertFalse($validator->usesNative());
        $this->assertTrue($validator->validate(['a' => 'x'])->isValid());
    }

    public function testPruneRemovesArtifactsFromOtherCompilerOrPhpVersions(): void
    {
        $repo = new NativeArtifactRepository($this->dir);
        $current = (string) $repo->store($this->schema());

        $stale = [];
        foreach ([['compiler' => '0.0.1'], ['php' => '70400'], ['format' => '0']] as $i => $override) {
            $body = "return 1;\n";
            $header = '// vi-validation-native ' . http_build_query(array_merge([
                'format' => NativeArtifactRepository::ARTIFACT_FORMAT,
                'key' => str_repeat((string) $i, 64),
                'compiler' => \Vi\Validation\Compilation\NativeCompiler::COMPILER_VERSION,
                'php' => PHP_VERSION_ID,
                'sha256' => hash('sha256', $body),
            ], $override));
            $stale[] = $path = $this->dir . '/' . str_repeat((string) $i, 64) . '.php';
            file_put_contents($path, "<?php\n{$header}\n{$body}");
        }
        file_put_contents($stale[] = $this->dir . '/garbage.php', 'not php at all');

        $this->assertSame(4, $repo->prune());
        $this->assertFileExists($current);
        foreach ($stale as $path) {
            $this->assertFileDoesNotExist($path);
        }
    }

    public function testClearRemovesEverythingAndForgetsMemoizedClosures(): void
    {
        $compiler = new ValidatorCompiler(null, false, $this->dir);
        $schema = $this->schema();
        $compiler->writeNativeFor($schema);
        $this->assertNotNull($compiler->loadNativeFor($schema));

        $this->assertSame(1, $compiler->clearNative());
        $this->assertNull($compiler->loadNativeFor($schema));
    }

    public function testLoadedClosuresAreMemoizedPerProcess(): void
    {
        $repo = new NativeArtifactRepository($this->dir);
        $schema = $this->schema();
        $path = (string) $repo->store($schema);

        $first = $repo->loadFor($schema);
        unlink($path);

        $this->assertSame($first, (new NativeArtifactRepository($this->dir))->loadFor($schema));

        NativeArtifactRepository::flushMemory();
        $this->assertNull($repo->loadFor($schema));
    }

    public function testSchemaValidatorResolvesNativeOnceNotPerRow(): void
    {
        $compiler = new ValidatorCompiler(null, false, $this->dir);
        $schema = $this->schema();
        $path = (string) $compiler->writeNativeFor($schema);

        $validator = new SchemaValidator($schema, new ValidatorEngine(), $compiler);
        $this->assertTrue($validator->validate(['name' => 'Bob', 'age' => 30])->isValid());

        unlink($path);
        NativeArtifactRepository::flushMemory();

        $this->assertTrue($validator->usesNative(), 'resolution happened once; later rows never touch disk');
        $this->assertFalse($validator->validate(['name' => 'Bob', 'age' => 3])->isValid());
    }

    public function testNoCachePathMeansNoNativeLookup(): void
    {
        $validator = new SchemaValidator($this->schema());

        $this->assertFalse($validator->usesNative());
        $this->assertTrue($validator->validate(['name' => 'Bob', 'age' => 30])->isValid());
    }

    public function testConcurrentGenerationProducesOneCompleteArtifact(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required to exercise concurrent writers.');
        }

        $schema = $this->schema();
        $key = (new NativeArtifactRepository($this->dir))->keyFor($schema);
        $children = [];

        for ($i = 0; $i < 8; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                NativeArtifactRepository::flushMemory();
                $ok = (new NativeArtifactRepository($this->dir))->store($schema) !== null;
                exit($ok ? 0 : 1);
            }
            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        $this->assertSame([$this->dir . '/' . $key . '.php'], glob($this->dir . '/*.php'));
        $this->assertSame([], glob($this->dir . '/.*.tmp') ?: []);
        $this->assertNotNull((new NativeArtifactRepository($this->dir))->load($key));
    }
}

final class BrokenNativeRule implements RuleInterface, NativeCompilableInterface
{
    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        return null;
    }

    public function compileNative(NativeCompilationContext $context): string
    {
        return $context->indent . "if ( { broken\n";
    }

    public function isImplicitForNative(): bool
    {
        return false;
    }
}
