<?php

declare(strict_types=1);

namespace Vi\Validation\Compilation;

/**
 * Passed to NativeCompilableInterface::compileNative() for each rule.
 *
 * This is the "generated-code mechanics" half of native compilation: it owns
 * the PHP variable/array-key formatting and the `$errors`/`$hasErrors`
 * bookkeeping convention, so a rule's compileNative() only has to describe
 * *when* it fails (a PHP boolean expression) and *what* to report - never
 * how the surrounding closure represents errors or indentation.
 */
final class NativeCompilationContext
{
    public function __construct(
        /** Original field name, used as the error array key. */
        public readonly string $fieldName,
        /** Name of the PHP variable (e.g. "$val_email") already holding the field's value. */
        public readonly string $valName,
        /** Current indentation string to prefix every emitted line with. */
        public readonly string $indent,
    ) {
    }

    /**
     * Same context at a deeper (or different) indentation level, e.g. inside
     * an `if`/`else` block a rule generates itself.
     */
    public function withIndent(string $indent): self
    {
        return new self($this->fieldName, $this->valName, $indent);
    }

    /**
     * A standalone `if (<condition>) { <error statement> }` block.
     *
     * $condition must be a PHP boolean expression (as source text, referring
     * to $this->valName and/or other in-scope variables) that is true when
     * the rule fails. $paramsPhpExpr, if given, is PHP source for the
     * `params` array reported alongside the rule name (mirrors the `params`
     * key a RuleInterface::validate() failure array may return).
     */
    public function emitError(string $condition, string $ruleName, ?string $paramsPhpExpr = null): string
    {
        return "{$this->indent}if ({$condition}) {\n"
            . $this->withIndent($this->indent . '    ')->errorStatement($ruleName, $paramsPhpExpr)
            . "{$this->indent}}\n";
    }

    /**
     * Just the `$errors[...][] = ...; $hasErrors = true;` statement pair, for
     * rules whose failure can't be expressed as a single boolean expression
     * (e.g. it needs a multi-branch if/else, or an intermediate variable).
     */
    public function errorStatement(string $ruleName, ?string $paramsPhpExpr = null): string
    {
        $fieldKey = var_export($this->fieldName, true);
        $ruleKey = var_export($ruleName, true);
        $paramsCode = $paramsPhpExpr !== null ? ", 'params' => {$paramsPhpExpr}" : '';

        return "{$this->indent}\$errors[{$fieldKey}][] = ['rule' => {$ruleKey}{$paramsCode}, 'message' => null];\n"
            . "{$this->indent}\$hasErrors = true;\n";
    }
}
