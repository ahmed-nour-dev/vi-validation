<?php

declare(strict_types=1);

namespace Vi\Validation\Diagnostics;

use ReflectionClass;
use Vi\Validation\Compilation\NativeCompiler;
use Vi\Validation\Compilation\ValidatorCompiler;
use Vi\Validation\Execution\CompiledSchema;
use Vi\Validation\Rules\ClosureRule;
use Vi\Validation\Rules\ConditionalRule;
use Vi\Validation\Rules\RuleId;
use Vi\Validation\Rules\RuleInterface;
use Vi\Validation\Rules\RuleName;

/**
 * Builds SchemaDiagnostics for a compiled schema.
 *
 * Inspection is read-only and entirely off the validation hot path. It never generates an
 * artifact and never executes one; it only looks at whether a valid artifact file exists.
 */
final class SchemaInspector
{
    /** @var array<class-string, string> */
    private static array $ruleNames = [];

    /**
     * @param bool|null $nativeResolved null when the validator hasn't resolved its strategy
     *        yet; otherwise whether a native closure was loaded.
     */
    public static function inspect(
        CompiledSchema $schema,
        ?ValidatorCompiler $compiler = null,
        ?bool $nativeResolved = null
    ): SchemaDiagnostics {
        $nativeCompiler = new NativeCompiler();
        $fingerprint = $schema->fingerprint();

        $fields = [];
        $unsupportedRules = [];
        $ruleCount = 0;

        foreach ($schema->getFields() as $field) {
            $name = $field->getName();
            $ruleNames = [];
            $unsupported = [];

            $flags = [];
            if ($field->isSometimes()) {
                $flags[] = 'sometimes';
            }
            if ($field->isAlwaysExcluded()) {
                $flags[] = 'exclude';
            }

            foreach ($field->getExclusionRules() as $rule) {
                $ruleNames[] = self::ruleName($rule);
                $ruleCount++;
                $unsupported[] = [
                    'rule' => self::ruleName($rule),
                    'reason' => 'exclude_* rules are only evaluated by ValidatorEngine',
                ];
            }

            if ($field->isAlwaysExcluded()) {
                $unsupported[] = ['rule' => 'exclude', 'reason' => 'exclude rules are only evaluated by ValidatorEngine'];
            }

            foreach ($field->getRules() as $rule) {
                $ruleNames[] = self::ruleName($rule);
                $ruleCount++;

                if (!$nativeCompiler->isSupported($rule)) {
                    $unsupported[] = ['rule' => self::ruleName($rule), 'reason' => self::unsupportedReason($rule)];
                }
            }

            foreach ($unsupported as $entry) {
                $unsupportedRules[] = ['field' => $name] + $entry;
            }

            $fields[] = [
                'name' => $name,
                'rules' => $ruleNames,
                'flags' => $flags,
                'native' => $unsupported === [],
                'unsupported' => $unsupported,
            ];
        }

        $nativeCompatible = $unsupportedRules === [];
        [$artifactStatus, $artifactPath] = self::artifactStatus($schema, $compiler, $nativeCompatible, $nativeResolved);
        [$strategy, $reason] = self::strategy($artifactStatus, $nativeCompatible, $fingerprint->stable, $unsupportedRules);

        return new SchemaDiagnostics(
            $fingerprint->schemaHash,
            $fingerprint->artifactKey,
            $fingerprint->stable,
            $fingerprint->unstableReasons,
            count($fields),
            $ruleCount,
            $fields,
            $nativeCompatible,
            $unsupportedRules,
            $artifactStatus,
            $artifactPath,
            $strategy,
            $reason,
            NativeCompiler::COMPILER_VERSION,
            PHP_VERSION_ID,
            $schema->getCompileTimeMs(),
        );
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private static function artifactStatus(
        CompiledSchema $schema,
        ?ValidatorCompiler $compiler,
        bool $nativeCompatible,
        ?bool $nativeResolved
    ): array {
        $repository = $compiler?->nativeRepository();

        if (!$schema->fingerprint()->stable) {
            return [SchemaDiagnostics::ARTIFACT_UNSTABLE, null];
        }
        if (!$nativeCompatible) {
            return [SchemaDiagnostics::ARTIFACT_NOT_COMPILABLE, null];
        }
        if ($compiler === null || $repository === null) {
            return [SchemaDiagnostics::ARTIFACT_NOT_CONFIGURED, null];
        }

        $path = $repository->pathFor($repository->keyFor($schema));

        if ($nativeResolved === true) {
            return [SchemaDiagnostics::ARTIFACT_LOADED, $path];
        }
        if ($repository->has($repository->keyFor($schema))) {
            return [SchemaDiagnostics::ARTIFACT_AVAILABLE, $path];
        }
        if ($nativeResolved === null && $compiler->isPrecompileEnabled()) {
            return [SchemaDiagnostics::ARTIFACT_WILL_GENERATE, $path];
        }

        return [SchemaDiagnostics::ARTIFACT_MISSING, $path];
    }

    /**
     * @param list<array{field: string, rule: string, reason: string}> $unsupportedRules
     * @return array{0: string, 1: string}
     */
    private static function strategy(string $artifactStatus, bool $nativeCompatible, bool $stable, array $unsupportedRules): array
    {
        return match ($artifactStatus) {
            SchemaDiagnostics::ARTIFACT_LOADED => [SchemaDiagnostics::STRATEGY_NATIVE, 'native artifact loaded'],
            SchemaDiagnostics::ARTIFACT_AVAILABLE => [SchemaDiagnostics::STRATEGY_NATIVE, 'native artifact available; loaded on first validate()'],
            SchemaDiagnostics::ARTIFACT_WILL_GENERATE => [SchemaDiagnostics::STRATEGY_NATIVE, 'precompile enabled; artifact generated on first validate()'],
            SchemaDiagnostics::ARTIFACT_UNSTABLE => [SchemaDiagnostics::STRATEGY_ENGINE, 'schema contains closures or other values without a stable identity'],
            SchemaDiagnostics::ARTIFACT_NOT_COMPILABLE => [
                SchemaDiagnostics::STRATEGY_ENGINE,
                'not natively compilable: ' . implode(', ', array_map(
                    static fn (array $u): string => $u['field'] . ':' . $u['rule'],
                    $unsupportedRules
                )),
            ],
            SchemaDiagnostics::ARTIFACT_NOT_CONFIGURED => [SchemaDiagnostics::STRATEGY_ENGINE, 'no compilation cache_path configured'],
            default => [SchemaDiagnostics::STRATEGY_ENGINE, 'no native artifact and precompile disabled (see FastValidatorFactory::precompile())'],
        };
    }

    private static function unsupportedReason(RuleInterface $rule): string
    {
        return match (true) {
            $rule instanceof ClosureRule => 'closure rules run user code and are only evaluated by ValidatorEngine',
            $rule instanceof ConditionalRule => 'conditional when() rules are only evaluated by ValidatorEngine',
            default => 'rule does not implement NativeCompilableInterface',
        };
    }

    private static function ruleName(RuleInterface $rule): string
    {
        $class = get_class($rule);

        if (!isset(self::$ruleNames[$class])) {
            $name = (new ReflectionClass($rule))->getShortName();
            foreach ((new ReflectionClass($rule))->getAttributes(RuleName::class) as $attribute) {
                $ruleName = $attribute->newInstance()->name;
                $name = $ruleName instanceof RuleId ? $ruleName->value : $ruleName;
                break;
            }
            self::$ruleNames[$class] = $name;
        }

        return self::$ruleNames[$class];
    }
}
