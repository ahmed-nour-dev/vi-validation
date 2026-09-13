<?php

declare(strict_types=1);

namespace Vi\Validation\Compilation;

use RuntimeException;

/**
 * Thrown when a schema contains one or more rules that NativeCompiler
 * cannot translate into inlined PHP code.
 *
 * A schema that cannot be fully compiled must never produce a partial
 * native validator, since that would silently change validation
 * semantics. Callers must catch this and fall back to ValidatorEngine.
 */
final class UnsupportedNativeRuleException extends RuntimeException
{
}
