<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Compilation\NativeCompilationContext;
use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::INTEGER, aliases: ['int'])]
final class IntegerTypeRule implements RuleInterface, NativeCompilableInterface
{
    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        if ($value === null) {
            return null;
        }

        if ($this->isInt($value)) {
            return null;
        }

        return ['rule' => 'integer'];

    }

    private function isInt(mixed $value): bool
    {
         if (is_int($value)) {
            return true;
        }

        if (!is_string($value) || $value === "") {
            return false;
        }

        if ($value[0] === "-") {
            return strlen($value) > 1 && ctype_digit(substr($value, 1));
        }

        return ctype_digit($value);
    }

    public function compileNative(NativeCompilationContext $context): string
    {
        $v = $context->valName;
        $condition = "!is_int({$v}) && !(is_string({$v}) && preg_match('/^-?\\d+\$/', {$v}))";

        return $context->emitError($condition, 'integer');
    }

    public function isImplicitForNative(): bool
    {
        return false;
    }
}
