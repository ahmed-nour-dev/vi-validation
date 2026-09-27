<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Compilation;

use Illuminate\Container\Container;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as IlluminateFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Vi\Validation\Cache\FileSchemaCache;
use Vi\Validation\Cache\PayloadSigner;
use Vi\Validation\Compilation\NativeArtifactRepository;
use Vi\Validation\Compilation\NativeCompiler;
use Vi\Validation\Compilation\ValidatorCompiler;
use Vi\Validation\Execution\CompiledSchema;
use Vi\Validation\Laravel\FastValidatorFactory;
use Vi\Validation\Laravel\FastValidatorWrapper;
use Vi\Validation\Laravel\LaravelRuleParser;
use Vi\Validation\Laravel\LaravelValidatorAdapter;
use Vi\Validation\Laravel\UnsupportedRuleException;
use Vi\Validation\Validator;

/**
 * Security regression tests for native code generation, artifact loading and cache files.
 * See docs/native-compilation.md "Security model & trust boundaries".
 */
#[Group('native')]
final class CodegenSecurityTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        NativeArtifactRepository::flushMemory();
        unset($GLOBALS['vi_pwned']);
        CodegenSecurityWakeupProbe::$woken = false;
        $this->dir = sys_get_temp_dir() . '/vi-validation-security-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        NativeArtifactRepository::flushMemory();
        unset($GLOBALS['vi_pwned']);

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            ($item->isDir() && !$item->isLink()) ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostileFieldNames(): array
    {
        return [
            'newline breaks out of comment' => ["x\n\$GLOBALS['vi_pwned'] = true; //"],
            'carriage return' => ["x\r\$GLOBALS['vi_pwned'] = true; //"],
            'close tag inside comment' => ["x ?><?php \$GLOBALS['vi_pwned'] = true; ?>"],
            'single quote' => ["a'] = 1; \$GLOBALS['vi_pwned'] = true; \$x['"],
            'double quote' => ['a"b'],
            'backslash before quote' => ["a\\'b"],
            'trailing backslash' => ['a\\'],
            'nul byte' => ["nul\0byte"],
            'variable interpolation' => ['${GLOBALS}{$x}'],
            'unicode' => ['حقل_名前_🙂'],
            'goto label lookalike' => ['bail_0'],
            'empty' => [''],
            'dotted with quote' => ["a'.b\"c"],
            'deep dotted with newline' => ["a.b\n.c"],
        ];
    }

    #[DataProvider('hostileFieldNames')]
    public function testHostileFieldNamesCannotInjectCodeAndMatchEngine(string $name): void
    {
        $schema = Validator::schema()
            ->field($name)->required()->string()->max(3)
            ->field('ok')->required()->integer()
            ->compile();

        $code = (new NativeCompiler())->compile($schema);

        // Parses as PHP (throws ParseError otherwise), without executing anything.
        token_get_all($code, TOKEN_PARSE);

        $path = $this->dir . '/native.php';
        file_put_contents($path, $code);
        $closure = require $path;

        $this->assertInstanceOf(\Closure::class, $closure);
        $this->assertArrayNotHasKey('vi_pwned', $GLOBALS, 'code was injected at require time');

        foreach ([[$name => 'abc', 'ok' => 1], [$name => 'abcdef', 'ok' => 'x'], [], ['ok' => 1]] as $data) {
            $native = $closure($data);
            $engine = $schema->validate($data);

            $this->assertArrayNotHasKey('vi_pwned', $GLOBALS, 'code was injected at run time');
            $this->assertSame($engine->isValid(), $native['valid']);
            $this->assertSame(
                array_map(static fn (array $e): array => array_column($e, 'rule'), $engine->errors()),
                array_map(static fn (array $e): array => array_column($e, 'rule'), $native['errors']),
                'native and engine must report the same fields/rules for ' . json_encode($data)
            );
        }
    }

    public function testFieldsWhoseSanitizedNamesCollideStayIndependent(): void
    {
        $schema = Validator::schema()
            ->field('a-b')->required()
            ->field('a_b')->required()
            ->field('a b')->required()->integer()
            ->compile();

        $code = (new NativeCompiler())->compile($schema);
        $path = $this->dir . '/collide.php';
        file_put_contents($path, $code);
        $closure = require $path;

        $result = $closure(['a_b' => 'present', 'a b' => 'x']);

        $this->assertSame(['a-b', 'a b'], array_keys($result['errors']));
        $this->assertSame('integer', $result['errors']['a b'][0]['rule']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function extremeNumericParams(): array
    {
        return [
            'infinite' => ['max:1e999'],
            'negative infinite' => ['min:-1e999'],
            'nan' => ['max:NAN'],
            'non numeric' => ['max:abc'],
            'huge' => ['max:9223372036854775807999'],
            'negative zero' => ['min:-0.0'],
        ];
    }

    #[DataProvider('extremeNumericParams')]
    public function testExtremeRuleParametersGenerateValidCodeMatchingEngine(string $rule): void
    {
        $parser = new LaravelRuleParser();
        $builder = Validator::schema();
        $builder->field('a')->rules(...$parser->parse('numeric|' . $rule, 'a'));
        $schema = $builder->compile();

        $code = (new NativeCompiler())->compile($schema);
        token_get_all($code, TOKEN_PARSE);
        $path = $this->dir . '/params.php';
        file_put_contents($path, $code);
        $closure = require $path;

        foreach ([0, 5, -5, '12', 1.5] as $value) {
            $this->assertSame($schema->validate(['a' => $value])->isValid(), $closure(['a' => $value])['valid'], $rule . ' / ' . var_export($value, true));
        }
    }

    public function testUnknownRulesFailClosed(): void
    {
        $this->expectException(UnsupportedRuleException::class);

        (new FastValidatorFactory())->make(['a' => 'x'], ['a' => 'requird|string']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rulesMissingParameters(): array
    {
        return [
            'required_if' => ['required_if'],
            'required_if one param' => ['required_if:other'],
            'max' => ['max'],
            'regex' => ['regex'],
            'same' => ['same'],
            'exists' => ['exists'],
        ];
    }

    #[DataProvider('rulesMissingParameters')]
    public function testRulesMissingParametersFailClosed(string $rule): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing required parameters');

        (new LaravelRuleParser())->parse($rule, 'a');
    }

    public function testUnsupportedRuleDefinitionTypesAreRejectedClearly(): void
    {
        $this->expectException(UnsupportedRuleException::class);

        /** @phpstan-ignore-next-line intentionally invalid */
        (new LaravelRuleParser())->parse(['required', new \stdClass()], 'a');
    }

    public function testOverrideModeFallsBackToLaravelForUnsupportedRulesAndKeepsMessages(): void
    {
        $translator = new Translator(new ArrayLoader(), 'en');
        $laravel = new IlluminateFactory($translator, new Container());
        $laravel->extend('even', static fn ($attribute, $value): bool => (int) $value % 2 === 0, 'The :attribute must be even.');

        $adapter = new LaravelValidatorAdapter(new FastValidatorFactory(), $laravel);

        // Rule only Laravel knows about: must be validated by Laravel, not skipped.
        $extended = $adapter->make(['n' => 3], ['n' => 'required|even']);
        $this->assertNotInstanceOf(FastValidatorWrapper::class, $extended);
        $this->assertTrue($extended->fails());

        // Supported rules stay on the fast path and keep custom messages/attributes.
        $fast = $adapter->make(['n' => ''], ['n' => 'required'], ['n.required' => 'Need :attribute!'], ['n' => 'number']);
        $this->assertInstanceOf(FastValidatorWrapper::class, $fast);
        $this->assertTrue($fast->fails());
        $this->assertSame('Need number!', $fast->errors()->first('n'));
    }

    public function testArtifactKeysCannotTraverseDirectories(): void
    {
        $repo = new NativeArtifactRepository($this->dir . '/native');

        foreach (['../escape', 'a/b', '..', '', "a\0b", str_repeat('a', 129), 'x.php'] as $key) {
            $this->assertNull($repo->load($key), var_export($key, true));
            try {
                $repo->pathFor($key);
                $this->fail('pathFor() accepted ' . var_export($key, true));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSymlinkedArtifactIsNeverExecuted(): void
    {
        $repo = new NativeArtifactRepository($this->dir . '/native');
        $schema = $this->schema();
        $path = (string) $repo->store($schema);

        $elsewhere = $this->dir . '/elsewhere.php';
        rename($path, $elsewhere);
        symlink($elsewhere, $path);
        NativeArtifactRepository::flushMemory();

        $this->assertNull($repo->loadFor($schema));
    }

    public function testArtifactInWorldWritableLocationIsNeverExecuted(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX permissions only.');
        }

        $repo = new NativeArtifactRepository($this->dir . '/native');
        $schema = $this->schema();
        $path = (string) $repo->store($schema);

        chmod($path, 0666);
        $this->assertNull($repo->loadFor($schema), 'world-writable file');
        chmod($path, 0644);

        chmod($this->dir . '/native', 0777);
        $this->assertNull($repo->loadFor($schema), 'world-writable dir without sticky bit');

        chmod($this->dir . '/native', 01777);
        $this->assertNotNull($repo->loadFor($schema), 'sticky world-writable dir (like /tmp) is acceptable');
        chmod($this->dir . '/native', 0755);
    }

    public function testSignedArtifactsRejectFilesWithoutTheSecret(): void
    {
        $schema = $this->schema();
        $signed = new NativeArtifactRepository($this->dir . '/native', new NativeCompiler(), 'secret-a');
        $path = (string) $signed->store($schema);
        $this->assertNotNull($signed->loadFor($schema));
        $this->assertStringContainsString('hmac=', (string) file_get_contents($path));

        NativeArtifactRepository::flushMemory();
        $this->assertNull(
            (new NativeArtifactRepository($this->dir . '/native', new NativeCompiler(), 'secret-b'))->loadFor($schema),
            'different secret'
        );

        // An attacker who can write the directory but doesn't know the secret can only
        // produce an unkeyed checksum; a keyed repository never runs it.
        NativeArtifactRepository::flushMemory();
        $unsigned = new NativeArtifactRepository($this->dir . '/native-unsigned');
        $plantedPath = (string) $unsigned->store($schema);
        @mkdir($this->dir . '/native2');
        copy($plantedPath, $this->dir . '/native2/' . basename($plantedPath));
        $this->assertNull((new NativeArtifactRepository($this->dir . '/native2', new NativeCompiler(), 'secret-a'))->loadFor($schema));
    }

    public function testFileSchemaCacheNeverUnserializesUnverifiedBytes(): void
    {
        $cache = new FileSchemaCache($this->dir . '/cache', 3600, 'app-secret');
        $cache->put('k', $this->schema());
        $this->assertInstanceOf(CompiledSchema::class, $cache->get('k'));

        $file = (string) glob($this->dir . '/cache/*.cache')[0];

        // Planted raw serialized payload (no envelope).
        file_put_contents($file, serialize(['schema' => new CodegenSecurityWakeupProbe(), 'expires' => null]));
        $this->assertNull($cache->get('k'));
        $this->assertFalse(CodegenSecurityWakeupProbe::$woken, 'unserialize() ran on planted bytes');

        // Planted payload with a valid *unkeyed* checksum.
        $cache->put('k', $this->schema());
        file_put_contents($file, PayloadSigner::wrap(serialize(['schema' => new CodegenSecurityWakeupProbe(), 'expires' => null]), null));
        $this->assertNull($cache->get('k'));
        $this->assertFalse(CodegenSecurityWakeupProbe::$woken, 'unserialize() ran on an unsigned payload');
    }

    public function testFileSchemaCacheWithoutKeyStillDetectsCorruptionAndSupportsNoExpiry(): void
    {
        $cache = new FileSchemaCache($this->dir . '/cache');
        $cache->put('forever', $this->schema(), 0);

        $this->assertInstanceOf(CompiledSchema::class, $cache->get('forever'), 'ttl 0 = never expires');

        $file = (string) glob($this->dir . '/cache/*.cache')[0];
        file_put_contents($file, substr((string) file_get_contents($file), 0, -10));
        $this->assertNull($cache->get('forever'));
        $this->assertSame([], glob($this->dir . '/cache/.*.tmp') ?: []);
    }

    public function testPrecompiledSchemasAreVerifiedBeforeUnserialize(): void
    {
        $compiler = new ValidatorCompiler(null, true, $this->dir . '/pre', 'secret');
        $compiler->compile('schema-key', [], fn (): CompiledSchema => $this->schema());
        $this->assertInstanceOf(CompiledSchema::class, $compiler->loadPrecompiled('schema-key'));

        $file = (string) glob($this->dir . '/pre/*.compiled')[0];
        file_put_contents($file, serialize(new CodegenSecurityWakeupProbe()));

        $this->assertNull($compiler->loadPrecompiled('schema-key'));
        $this->assertFalse(CodegenSecurityWakeupProbe::$woken);
    }

    private function schema(): CompiledSchema
    {
        return Validator::schema()->field('name')->required()->string()->compile();
    }
}

final class CodegenSecurityWakeupProbe
{
    public static bool $woken = false;

    public function __wakeup(): void
    {
        self::$woken = true;
    }
}
