<?php

declare(strict_types=1);

namespace Vi\Validation\Compilation;

/**
 * Shared codegen for MinRule/MaxRule, which are identical apart from the
 * comparison direction and the reported rule name. Kept out of the rule
 * classes themselves purely to avoid duplicating this one non-trivial block
 * twice - it is still the rule that decides what to compile (limit, numeric
 * mode, operator), this just emits the code for it.
 */
final class NativeMinMaxCompiler
{
    public static function compile(
        NativeCompilationContext $context,
        int|float $limit,
        bool $isNumeric,
        string $ruleName,
        string $operator
    ): string {
        $v = $context->valName;
        $indent = $context->indent;
        $limitExport = var_export($limit, true);
        $numericCheck = $isNumeric ? "is_numeric({$v})" : "(is_int({$v}) || is_float({$v}))";
        $errorStatement = $context->withIndent($indent . '    ')
            ->errorStatement($ruleName, "['type' => \$type_tag, '{$ruleName}' => {$limitExport}]");

        return "{$indent}if ({$v} !== null) {\n"
            . "{$indent}    \$invalid = false;\n"
            . "{$indent}    if ({$numericCheck}) {\n"
            . "{$indent}        if ((float){$v} {$operator} {$limitExport}) \$invalid = true;\n"
            . "{$indent}        \$type_tag = 'numeric';\n"
            . "{$indent}    } elseif (is_string({$v})) {\n"
            . "{$indent}        if (mb_strlen({$v}) {$operator} {$limitExport}) \$invalid = true;\n"
            . "{$indent}        \$type_tag = 'string';\n"
            . "{$indent}    } elseif (is_array({$v})) {\n"
            . "{$indent}        if (count({$v}) {$operator} {$limitExport}) \$invalid = true;\n"
            . "{$indent}        \$type_tag = 'array';\n"
            . "{$indent}    } else {\n"
            . "{$indent}        \$type_tag = 'numeric';\n"
            . "{$indent}    }\n"
            . "{$indent}    if (\$invalid) {\n"
            . $errorStatement
            . "{$indent}    }\n"
            . "{$indent}}\n";
    }
}
