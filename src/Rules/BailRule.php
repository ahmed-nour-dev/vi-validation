<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Compilation\NativeCompilationContext;
use Vi\Validation\Execution\ValidationContext;

/**
 * Marker rule for 'bail' logic.
 */
#[RuleName(RuleId::BAIL)]
final class BailRule implements RuleInterface, NativeCompilableInterface
{
    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        return null;
    }

    /**
     * Pure marker. `bail` is implemented at field level: after each rule NativeCompiler emits
     * a `goto` past the field's remaining rules once it has an error (CompiledField::isBail()),
     * exactly mirroring ValidatorEngine, so the rule itself emits no code.
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
