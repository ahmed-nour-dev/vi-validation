<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Compilation\NativeCompilationContext;
use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::BOOLEAN, aliases: ['bool'])]
final class BooleanRule implements RuleInterface, NativeCompilableInterface
{
    private const ACCEPTABLE = [true, false, 0, 1, '0', '1'];

    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        if ($value === null) {
            return null;
        }

        if (!in_array($value, self::ACCEPTABLE, true)) {
            return ['rule' => 'boolean'];
        }

        return null;
    }

    public function compileNative(NativeCompilationContext $context): string
    {
        $v = $context->valName;

        return $context->emitError(
            "{$v} !== null && !in_array({$v}, [true, false, 0, 1, '0', '1'], true)",
            'boolean'
        );
    }

    public function isImplicitForNative(): bool
    {
        return false;
    }
}
