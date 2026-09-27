<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Compilation\NativeCompilationContext;
use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::NULLABLE)]
final class NullableRule implements RuleInterface, NativeCompilableInterface
{
    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        // Nullable is handled by short-circuiting other rules; this rule itself never fails.
        return null;
    }

    /**
     * Pure marker. `nullable` is implemented at field level: NativeCompiler wraps the field's
     * rules in `if ($val !== null)` (CompiledField::isNullable()), exactly mirroring
     * ValidatorEngine, so the rule itself emits no code.
     */
    public function compileNative(NativeCompilationContext $context): string
    {
        return '';
    }

    public function isImplicitForNative(): bool
    {
        return true;
    }
}
