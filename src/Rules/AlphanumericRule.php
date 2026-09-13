<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Compilation\NativeCompilationContext;
use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::ALPHA_NUM)]
final class AlphanumericRule implements RuleInterface, NativeCompilableInterface
{
    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value) && !is_numeric($value)) {
            return ['rule' => 'alpha_num'];
        }

        if (!preg_match('/^[\pL\pM\pN]+$/u', (string) $value)) {
            return ['rule' => 'alpha_num'];
        }

        return null;
    }

    public function compileNative(NativeCompilationContext $context): string
    {
        $v = $context->valName;
        $condition = "{$v} !== null && ((!is_string({$v}) && !is_numeric({$v})) "
            . "|| !preg_match('/^[\\pL\\pM\\pN]+\$/u', (string) {$v}))";

        return $context->emitError($condition, 'alpha_num');
    }

    public function isImplicitForNative(): bool
    {
        return false;
    }
}
