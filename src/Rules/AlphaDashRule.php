<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Compilation\NativeCompilationContext;
use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::ALPHA_DASH)]
final class AlphaDashRule implements RuleInterface, NativeCompilableInterface
{
    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value) && !is_numeric($value)) {
            return ['rule' => 'alpha_dash'];
        }

        if (!preg_match('/^[\pL\pM\pN_-]+$/u', (string) $value)) {
            return ['rule' => 'alpha_dash'];
        }

        return null;
    }

    public function compileNative(NativeCompilationContext $context): string
    {
        $v = $context->valName;
        $condition = "{$v} !== null && ((!is_string({$v}) && !is_numeric({$v})) "
            . "|| !preg_match('/^[\\pL\\pM\\pN_-]+\$/u', (string) {$v}))";

        return $context->emitError($condition, 'alpha_dash');
    }

    public function isImplicitForNative(): bool
    {
        return false;
    }
}
