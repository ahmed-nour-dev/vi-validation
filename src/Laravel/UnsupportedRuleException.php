<?php

declare(strict_types=1);

namespace Vi\Validation\Laravel;

use InvalidArgumentException;

/**
 * Thrown by LaravelRuleParser for a rule vi/validation doesn't implement: an unknown rule
 * name (including rules registered only via Laravel's Validator::extend()) or a rule
 * definition of an unsupported type.
 *
 * In override mode LaravelValidatorAdapter catches it and hands the whole validation to
 * Laravel's own validator, so an unsupported rule is never silently skipped.
 */
final class UnsupportedRuleException extends InvalidArgumentException
{
}
