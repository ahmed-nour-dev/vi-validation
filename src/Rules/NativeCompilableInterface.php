<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Compilation\NativeCompilationContext;

/**
 * Optional capability a rule declares to opt into NativeCompiler inlining.
 *
 * This is the explicit contract required in place of NativeCompiler's old
 * hardcoded `match (get_class($rule))` list: a rule is native-compilable if
 * and only if it implements this interface. NativeCompiler never assumes
 * compilability from anything else (class name, other interfaces, etc.), so
 * a new rule that doesn't implement it is correctly treated as unsupported
 * and forces the engine fallback rather than being silently skipped.
 *
 * compileNative() must generate code that is exactly equivalent to
 * validate() for every input - NativeCompiler and ValidatorEngine are
 * required to agree on every result. Implementations should use
 * $context->emitError()/errorStatement() rather than building the
 * `$errors`/`$hasErrors` bookkeeping by hand, so that mechanic stays in one
 * place (NativeCompilationContext) instead of being duplicated per rule.
 *
 * Custom, user-defined rules may implement this interface too: any rule
 * class - built-in or not - that implements it becomes eligible for native
 * inlining the moment NativeCompiler encounters it, with no registry
 * changes required elsewhere.
 */
interface NativeCompilableInterface
{
    /**
     * Emit inlined PHP source that performs this rule's check natively.
     *
     * $context->valName already holds the field's value and is guaranteed
     * non-null when isImplicitForNative() is false (NativeCompiler wraps
     * non-implicit rules in an emptiness guard before calling this).
     */
    public function compileNative(NativeCompilationContext $context): string;

    /**
     * Whether this rule must still run when the field value is empty/null
     * (mirrors the "implicit rule" concept in ValidatorEngine::isImplicitRule()
     * for RequiredRule and friends). Almost every rule should return false
     * here; only rules whose whole purpose is checking for emptiness/presence
     * return true.
     */
    public function isImplicitForNative(): bool;
}
