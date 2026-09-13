<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Compilation\NativeCompilationContext;
use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::ARRAY)]
final class ArrayRule implements RuleInterface, NativeCompilableInterface
{
    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            return ['rule' => 'array'];
        }

        return null;
    }

    public function compileNative(NativeCompilationContext $context): string
    {
        $v = $context->valName;

        return $context->emitError("{$v} !== null && !is_array({$v})", 'array');
    }

    public function isImplicitForNative(): bool
    {
        return false;
    }
}
