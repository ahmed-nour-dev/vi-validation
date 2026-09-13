<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Compilation\NativeCompilationContext;
use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::ALPHA)]
final class AlphaRule implements RuleInterface, NativeCompilableInterface
{
    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value) || !preg_match('/^[\pL\pM]+$/u', $value)) {
            return ['rule' => 'alpha'];
        }

        return null;
    }

    public function compileNative(NativeCompilationContext $context): string
    {
        $v = $context->valName;
        $condition = "{$v} !== null && (!is_string({$v}) || !preg_match('/^[\\pL\\pM]+\$/u', {$v}))";

        return $context->emitError($condition, 'alpha');
    }

    public function isImplicitForNative(): bool
    {
        return false;
    }
}
