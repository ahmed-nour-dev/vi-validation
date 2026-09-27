<?php

declare(strict_types=1);

namespace Vi\Validation\Compilation;

use RuntimeException;

/**
 * Thrown by NativeArtifactRepository when a native artifact can't be generated or persisted
 * safely (invalid generated PHP, unwritable directory, failed atomic rename, ...).
 *
 * ValidatorCompiler catches it: failing to produce an artifact is never allowed to break
 * validation, which simply keeps using ValidatorEngine.
 */
final class NativeArtifactException extends RuntimeException
{
}
