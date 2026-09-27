<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Diagnostics;

use PHPUnit\Framework\TestCase;
use Vi\Validation\Compilation\NativeArtifactRepository;
use Vi\Validation\Compilation\NativeCompiler;
use Vi\Validation\Compilation\ValidatorCompiler;
use Vi\Validation\Diagnostics\SchemaDiagnostics;
use Vi\Validation\Laravel\FastValidatorFactory;
use Vi\Validation\SchemaValidator;
use Vi\Validation\Validator;

final class SchemaDiagnosticsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        NativeArtifactRepository::flushMemory();
        NativeArtifactRepository::resetStats();
        $this->dir = sys_get_temp_dir() . '/vi-validation-diag-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        NativeArtifactRepository::flushMemory();
        if (is_dir($this->dir)) {
            (new ValidatorCompiler(null, false, $this->dir))->clearNative();
            @unlink($this->dir . '/native/.lock');
            @rmdir($this->dir . '/native');
            @rmdir($this->dir);
        }
    }

    public function testReportsStructureAndNativeSupportPerField(): void
    {
        $schema = Validator::fromRules([
            'email' => 'required|email',
            'role' => 'required|in:admin,secret-internal-role',
            'nick' => 'sometimes|nullable|string',
        ]);

        $d = (new SchemaValidator($schema))->diagnostics();

        $this->assertSame($schema->fingerprint()->schemaHash, $d->schemaHash);
        $this->assertSame($schema->fingerprint()->artifactKey, $d->artifactKey);
        $this->assertTrue($d->stable);
        $this->assertSame(3, $d->fieldCount);
        $this->assertSame(6, $d->ruleCount);
        $this->assertSame(NativeCompiler::COMPILER_VERSION, $d->compilerVersion);
        $this->assertSame(PHP_VERSION_ID, $d->phpVersionId);
        $this->assertNotNull($d->compileTimeMs);

        $this->assertSame(['required', 'email'], $d->fields[0]['rules']);
        $this->assertTrue($d->fields[0]['native']);
        $this->assertFalse($d->fields[1]['native']);
        $this->assertSame(['sometimes'], $d->fields[2]['flags']);

        $this->assertFalse($d->nativeCompatible);
        $this->assertSame([['field' => 'role', 'rule' => 'in', 'reason' => 'rule does not implement NativeCompilableInterface']], $d->unsupportedRules);
        $this->assertSame(SchemaDiagnostics::ARTIFACT_NOT_COMPILABLE, $d->artifactStatus);
        $this->assertSame(SchemaDiagnostics::STRATEGY_ENGINE, $d->strategy);
        $this->assertStringContainsString('role:in', $d->strategyReason);
    }

    public function testNeverLeaksRuleParametersOrData(): void
    {
        $validator = new SchemaValidator(Validator::fromRules([
            'token' => 'required|in:sk_live_SUPERSECRET|regex:/^hunter2$/',
        ]));
        $validator->validate(['token' => 'user-provided-secret-value']);

        $dump = json_encode($validator->diagnostics()->toArray()) . (string) $validator->diagnostics();

        $this->assertStringNotContainsString('SUPERSECRET', $dump);
        $this->assertStringNotContainsString('hunter2', $dump);
        $this->assertStringNotContainsString('user-provided-secret-value', $dump);
    }

    public function testStrategyTransitionsThroughTheLifecycle(): void
    {
        $schema = Validator::fromRules(['email' => 'required|email']);

        $this->assertSame(
            SchemaDiagnostics::ARTIFACT_NOT_CONFIGURED,
            (new SchemaValidator($schema))->diagnostics()->artifactStatus
        );

        $lazy = new SchemaValidator($schema, null, new ValidatorCompiler(null, false, $this->dir));
        $this->assertSame(SchemaDiagnostics::ARTIFACT_MISSING, $lazy->diagnostics()->artifactStatus);
        $this->assertSame(SchemaDiagnostics::STRATEGY_ENGINE, $lazy->diagnostics()->strategy);

        $precompiling = new SchemaValidator($schema, null, new ValidatorCompiler(null, true, $this->dir));
        $before = $precompiling->diagnostics();
        $this->assertSame(SchemaDiagnostics::ARTIFACT_WILL_GENERATE, $before->artifactStatus);
        $this->assertSame(SchemaDiagnostics::STRATEGY_NATIVE, $before->strategy);
        $this->assertSame([], glob($this->dir . '/native/*.php') ?: [], 'diagnostics must not generate artifacts');

        $precompiling->validate(['email' => 'a@example.com']);
        $after = $precompiling->diagnostics();
        $this->assertSame(SchemaDiagnostics::ARTIFACT_LOADED, $after->artifactStatus);
        $this->assertNotNull($after->artifactPath);
        $this->assertFileExists($after->artifactPath);

        $fresh = new SchemaValidator($schema, null, new ValidatorCompiler(null, false, $this->dir));
        $this->assertSame(SchemaDiagnostics::ARTIFACT_AVAILABLE, $fresh->diagnostics()->artifactStatus);
    }

    public function testUnstableSchemasExplainWhy(): void
    {
        $d = (new FastValidatorFactory())->diagnose([
            'a' => ['required', static function ($attribute, $value, $fail): void {
            }],
        ]);

        $this->assertFalse($d->stable);
        $this->assertNotSame([], $d->unstableReasons);
        $this->assertSame(SchemaDiagnostics::ARTIFACT_UNSTABLE, $d->artifactStatus);
        $this->assertSame('closure rules run user code and are only evaluated by ValidatorEngine', $d->fields[0]['unsupported'][0]['reason']);
    }

    public function testExclusionRulesAreReportedAsEngineOnly(): void
    {
        $d = (new FastValidatorFactory())->diagnose(['a' => 'exclude_if:b,1|string', 'c' => 'exclude|string']);

        $this->assertSame('exclude_if', $d->unsupportedRules[0]['rule']);
        $this->assertSame(['exclude'], $d->fields[1]['flags']);
        $this->assertFalse($d->fields[1]['native']);
    }

    public function testHumanReadableSummary(): void
    {
        $text = (string) (new FastValidatorFactory())->diagnose(['email' => 'required|email', 'role' => 'in:a,b']);

        $this->assertStringContainsString('(2 fields, 3 rules)', $text);
        $this->assertStringContainsString('Strategy: engine', $text);
        $this->assertStringContainsString('✓ email: required|email', $text);
        $this->assertStringContainsString('✗ role: in', $text);
    }

    public function testCacheAndArtifactCounters(): void
    {
        $factory = new FastValidatorFactory(['compilation' => ['cache_path' => $this->dir, 'precompile' => true]]);

        $factory->make(['a' => 'x'], ['a' => 'required|string'])->passes();
        $factory->make(['a' => 'x'], ['a' => 'required|string'])->passes();
        $factory->make(['a' => 'x'], ['a' => [static fn ($attr, $value, $fail) => null]])->passes();

        $this->assertSame(['hits' => 1, 'misses' => 1, 'uncacheable' => 1], $factory->cacheStats());

        $stats = NativeArtifactRepository::stats();
        $this->assertSame(1, $stats['generated']);
        $this->assertSame(1, $stats['loaded']);
        $this->assertSame(1, $stats['memory_hits']);
        $this->assertSame(0, $stats['rejected']);
    }
}
