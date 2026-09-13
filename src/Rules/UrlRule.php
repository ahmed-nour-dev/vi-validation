<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Compilation\NativeCompilationContext;
use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::URL)]
final class UrlRule implements RuleInterface, NativeCompilableInterface
{
    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            return ['rule' => 'url'];
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return ['rule' => 'url'];
        }

        return null;
    }

    public function compileNative(NativeCompilationContext $context): string
    {
        $v = $context->valName;
        $condition = "{$v} !== null && (!is_string({$v}) || filter_var({$v}, FILTER_VALIDATE_URL) === false)";

        return $context->emitError($condition, 'url');
    }

    public function isImplicitForNative(): bool
    {
        return false;
    }
}
