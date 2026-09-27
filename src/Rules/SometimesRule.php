<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Compilation\NativeCompilationContext;
use Vi\Validation\Execution\ValidationContext;

/**
 * Marker rule for 'sometimes' logic.
 */
#[RuleName(RuleId::SOMETIMES)]
final class SometimesRule implements RuleInterface, NativeCompilableInterface
{
    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        return null;
    }

    /**
     * Pure marker. `sometimes` is consumed into CompiledField::isSometimes() at compile time and
     * NativeCompiler wraps the field in a presence check, exactly mirroring ValidatorEngine, so
     * the rule itself emits no code.
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
