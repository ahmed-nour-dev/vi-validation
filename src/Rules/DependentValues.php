<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

/**
 * Laravel's dependent-value matching for `*_if` / `*_unless` rules
 * (Validator::parseDependentRuleParameters() + the in_array() that follows it).
 *
 * Rule parameters are strings, while the other field's value may be a bool, int, float or
 * null (JSON input). Like Laravel:
 * - if the other value is a bool, the parameters 'true'/'false' become booleans;
 * - if it is null, the parameter 'null' (case-insensitive) becomes null;
 * - the comparison is strict for bool/null, loose otherwise (so 1 matches '1' and '1.0').
 *
 * @internal
 */
final class DependentValues
{
    /**
     * @param list<mixed> $values
     */
    public static function matches(mixed $other, array $values): bool
    {
        if (is_bool($other)) {
            $values = array_map(
                static fn (mixed $v): mixed => $v === 'true' ? true : ($v === 'false' ? false : $v),
                $values
            );
        } elseif ($other === null) {
            $values = array_map(
                static fn (mixed $v): mixed => is_string($v) && strtolower($v) === 'null' ? null : $v,
                $values
            );
        }

        if (is_array($other) || is_object($other)) {
            return in_array($other, $values, true);
        }

        return in_array($other, $values, is_bool($other) || $other === null);
    }

    /**
     * @param list<mixed> $values
     */
    public static function describe(array $values): string
    {
        return implode(', ', array_map(static function (mixed $v): string {
            if ($v === null) {
                return 'null';
            }
            if (is_bool($v)) {
                return $v ? 'true' : 'false';
            }

            return is_scalar($v) ? (string) $v : get_debug_type($v);
        }, $values));
    }
}
