<?php

declare(strict_types=1);

namespace Vi\Validation\Diagnostics;

/**
 * Read-only snapshot of how a compiled schema will be executed and why.
 *
 * Contains only structural information - field names, rule names, flags, hashes, versions
 * and artifact status. It never contains rule parameters (an `in:` list or a `regex:` could
 * be sensitive) or any validated data, so it is safe to log or render in a debug toolbar.
 */
final class SchemaDiagnostics
{
    public const STRATEGY_NATIVE = 'native';
    public const STRATEGY_ENGINE = 'engine';

    public const ARTIFACT_LOADED = 'loaded';
    public const ARTIFACT_AVAILABLE = 'available';
    public const ARTIFACT_MISSING = 'missing';
    public const ARTIFACT_WILL_GENERATE = 'will-generate';
    public const ARTIFACT_NOT_CONFIGURED = 'not-configured';
    public const ARTIFACT_NOT_COMPILABLE = 'not-compilable';
    public const ARTIFACT_UNSTABLE = 'unstable-schema';

    /**
     * @param list<string> $unstableReasons
     * @param list<array{name: string, rules: list<string>, flags: list<string>, native: bool, unsupported: list<array{rule: string, reason: string}>}> $fields
     * @param list<array{field: string, rule: string, reason: string}> $unsupportedRules
     */
    public function __construct(
        public readonly string $schemaHash,
        public readonly string $artifactKey,
        public readonly bool $stable,
        public readonly array $unstableReasons,
        public readonly int $fieldCount,
        public readonly int $ruleCount,
        public readonly array $fields,
        public readonly bool $nativeCompatible,
        public readonly array $unsupportedRules,
        public readonly string $artifactStatus,
        public readonly ?string $artifactPath,
        public readonly string $strategy,
        public readonly string $strategyReason,
        public readonly string $compilerVersion,
        public readonly int $phpVersionId,
        public readonly ?float $compileTimeMs,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_hash' => $this->schemaHash,
            'artifact_key' => $this->artifactKey,
            'stable' => $this->stable,
            'unstable_reasons' => $this->unstableReasons,
            'field_count' => $this->fieldCount,
            'rule_count' => $this->ruleCount,
            'fields' => $this->fields,
            'native_compatible' => $this->nativeCompatible,
            'unsupported_rules' => $this->unsupportedRules,
            'artifact_status' => $this->artifactStatus,
            'artifact_path' => $this->artifactPath,
            'strategy' => $this->strategy,
            'strategy_reason' => $this->strategyReason,
            'compiler_version' => $this->compilerVersion,
            'php_version_id' => $this->phpVersionId,
            'compile_time_ms' => $this->compileTimeMs,
        ];
    }

    public function __toString(): string
    {
        $lines = [
            sprintf('Schema %s (%d fields, %d rules)', substr($this->schemaHash, 0, 12), $this->fieldCount, $this->ruleCount),
            sprintf('Strategy: %s - %s', $this->strategy, $this->strategyReason),
            sprintf('Artifact: %s%s', $this->artifactStatus, $this->artifactPath !== null ? " ({$this->artifactPath})" : ''),
            sprintf(
                'Compiler %s, PHP %d%s',
                $this->compilerVersion,
                $this->phpVersionId,
                $this->compileTimeMs !== null ? sprintf(', compiled in %.3f ms', $this->compileTimeMs) : ''
            ),
        ];

        if (!$this->stable) {
            $lines[] = 'Unstable fingerprint: ' . implode('; ', $this->unstableReasons);
        }

        foreach ($this->fields as $field) {
            $flags = $field['flags'] !== [] ? ' [' . implode(',', $field['flags']) . ']' : '';
            $lines[] = sprintf('  %s %s%s: %s', $field['native'] ? '✓' : '✗', $field['name'], $flags, implode('|', $field['rules']));
            foreach ($field['unsupported'] as $unsupported) {
                $lines[] = sprintf('      ✗ %s: %s', $unsupported['rule'], $unsupported['reason']);
            }
        }

        return implode("\n", $lines);
    }
}
