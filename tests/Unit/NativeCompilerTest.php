<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Vi\Validation\Compilation\NativeCompiler;
use Vi\Validation\Compilation\UnsupportedNativeRuleException;
use Vi\Validation\Compilation\ValidatorCompiler;
use Vi\Validation\Execution\ValidatorEngine;
use Vi\Validation\Rules\InRule;
use Vi\Validation\Rules\RequiredRule;
use Vi\Validation\Validator;

#[Group('native')]
class NativeCompilerTest extends TestCase
{
    public function testCanCompileReturnsTrueForFullySupportedSchema(): void
    {
        $schema = Validator::schema()
            ->field('name')->required()->string()->max(100)
            ->field('email')->required()->email()
            ->field('age')->required()->integer()->min(18)
            ->compile();

        $compiler = new NativeCompiler();

        $this->assertTrue($compiler->canCompile($schema));
        $this->assertSame([], $compiler->findUnsupportedRules($schema));
    }

    public function testCanCompileReturnsFalseWhenSchemaHasAnUnsupportedRule(): void
    {
        $schema = Validator::schema()
            ->field('name')->required()->string()
            ->field('status')->required()->in('active', 'inactive')
            ->compile();

        $compiler = new NativeCompiler();

        $this->assertFalse($compiler->canCompile($schema));

        $unsupported = $compiler->findUnsupportedRules($schema);
        $this->assertCount(1, $unsupported);
        $this->assertSame('status:' . InRule::class, $unsupported[0]);
    }

    public function testIsSupportedDistinguishesInlinableFromNonInlinableRules(): void
    {
        $compiler = new NativeCompiler();

        $this->assertTrue($compiler->isSupported(new RequiredRule()));
        $this->assertFalse($compiler->isSupported(new InRule(['a', 'b'])));
    }

    /**
     * Acceptance criterion: "No supported rule can be silently omitted from
     * native execution." Compiling a schema with an unsupported rule must
     * fail loudly, never emit a partial validator.
     */
    public function testCompileThrowsInsteadOfSilentlySkippingUnsupportedRule(): void
    {
        $schema = Validator::schema()
            ->field('name')->required()->string()
            ->field('code')->required()->regex('/^[A-Z]{3}$/')
            ->compile();

        $compiler = new NativeCompiler();

        $this->expectException(UnsupportedNativeRuleException::class);
        $this->expectExceptionMessageMatches('/code.*RegexRule/');

        $compiler->compile($schema);
    }

    public function testGeneratedCodeNeverContainsASkippingWarningComment(): void
    {
        $schema = Validator::schema()
            ->field('name')->required()->string()->max(50)
            ->field('email')->required()->email()
            ->compile();

        $compiler = new NativeCompiler();
        $code = $compiler->compile($schema);

        $this->assertStringNotContainsString('not inlined', $code);
        $this->assertStringNotContainsString('Skipping', $code);
    }

    /**
     * Acceptance criterion: native and engine paths must produce equivalent
     * validity/results for schemas mixing supported and unsupported rules.
     * Since the schema is not natively compilable, the only correct
     * behavior is to fall back entirely to ValidatorEngine.
     */
    public function testEngineFallbackProducesCorrectResultsForMixedSchema(): void
    {
        $schema = Validator::schema()
            ->field('name')->required()->string()->max(10)
            ->field('role')->required()->in('admin', 'member')
            ->compile();

        $compiler = new NativeCompiler();
        $this->assertFalse($compiler->canCompile($schema));

        $engine = new ValidatorEngine();

        // A value that a naive "skip the unsupported rule" native compiler
        // would have wrongly accepted, because it can't check `in`.
        $invalid = $engine->validate($schema, ['name' => 'Bob', 'role' => 'superuser']);
        $this->assertFalse($invalid->isValid());
        $this->assertArrayHasKey('role', $invalid->errors());

        $valid = $engine->validate($schema, ['name' => 'Bob', 'role' => 'admin']);
        $this->assertTrue($valid->isValid());
    }

    /**
     * Acceptance criterion: a schema containing an unsupported rule
     * deterministically falls back to the regular engine rather than
     * producing a native artifact.
     */
    public function testValidatorCompilerRefusesToWriteNativeArtifactForUnsupportedSchema(): void
    {
        $schema = Validator::schema()
            ->field('name')->required()->string()
            ->field('role')->required()->in('admin', 'member')
            ->compile();

        $cacheDir = sys_get_temp_dir() . '/vi-validation-native-test-' . uniqid('', true);
        mkdir($cacheDir, 0755, true);

        try {
            $validatorCompiler = new ValidatorCompiler(null, false, $cacheDir);
            $key = NativeCompiler::generateKey($schema->getRulesArray());

            $validatorCompiler->writeNative($key, $schema);

            $this->assertFileDoesNotExist($validatorCompiler->getNativePath($key));
        } finally {
            $this->removeDirectory($cacheDir);
        }
    }

    public function testValidatorCompilerWritesNativeArtifactForFullySupportedSchema(): void
    {
        $schema = Validator::schema()
            ->field('name')->required()->string()->max(10)
            ->field('age')->required()->integer()->min(18)
            ->compile();

        $cacheDir = sys_get_temp_dir() . '/vi-validation-native-test-' . uniqid('', true);
        mkdir($cacheDir, 0755, true);

        try {
            $validatorCompiler = new ValidatorCompiler(null, false, $cacheDir);
            $key = NativeCompiler::generateKey($schema->getRulesArray());

            $validatorCompiler->writeNative($key, $schema);

            $nativePath = $validatorCompiler->getNativePath($key);
            $this->assertFileExists($nativePath);

            $closure = require $nativePath;
            $this->assertInstanceOf(\Closure::class, $closure);

            $result = $closure(['name' => 'Bob', 'age' => 25]);
            $this->assertTrue($result['valid']);
        } finally {
            $this->removeDirectory($cacheDir);
        }
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
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
