<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Compilation\NativeCompilationContext;
use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::REQUIRED)]
final class RequiredRule implements RuleInterface, NativeCompilableInterface
{
    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        if ($value === null) {
            return ['rule' => 'required'];
        }

        if (is_string($value) && $value === '') {
            return ['rule' => 'required'];
        }

        if (is_array($value) && $value === []) {
            return ['rule' => 'required'];
        }

        return null;
    }

    public function compileNative(NativeCompilationContext $context): string
    {
        $v = $context->valName;
        $condition = "{$v} === null || (is_string({$v}) && {$v} === '') || (is_array({$v}) && {$v} === [])";

        return $context->emitError($condition, 'required');
    }

    public function isImplicitForNative(): bool
    {
        return true;
    }
}
