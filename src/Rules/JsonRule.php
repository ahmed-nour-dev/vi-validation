<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Compilation\NativeCompilationContext;
use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::JSON)]
final class JsonRule implements RuleInterface, NativeCompilableInterface
{
    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            return ['rule' => 'json'];
        }

        json_decode($value);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['rule' => 'json'];
        }

        return null;
    }

    public function compileNative(NativeCompilationContext $context): string
    {
        $v = $context->valName;
        $indent = $context->indent;
        $inner = $context->withIndent($indent . '    ');
        $innerElse = $context->withIndent($indent . '        ');

        return "{$indent}if (!is_string({$v})) {\n"
            . $inner->errorStatement('json')
            . "{$indent}} else {\n"
            . "{$indent}    json_decode({$v});\n"
            . "{$indent}    if (json_last_error() !== JSON_ERROR_NONE) {\n"
            . $innerElse->errorStatement('json')
            . "{$indent}    }\n"
            . "{$indent}}\n";
    }

    public function isImplicitForNative(): bool
    {
        return false;
    }
}
