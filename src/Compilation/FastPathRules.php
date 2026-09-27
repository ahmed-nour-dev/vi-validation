<?php

declare(strict_types=1);

namespace Vi\Validation\Compilation;

use Vi\Validation\Execution\ValidationContext;

/**
 * Optimized fast-path implementations for common validation rules.
 * These bypass the normal rule object creation for maximum performance.
 */
final class FastPathRules
{
    /**
     * Fast required check.
     */
    public static function required(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value) && \Vi\Validation\Execution\Emptiness::isBlankString($value)) {
            return false;
        }

        if (is_array($value) && $value === []) {
            return false;
        }

        return true;
    }

    /**
     * Fast string type check.
     */
    public static function string(mixed $value): bool
    {
        return $value === null || is_string($value);
    }

    /**
     * Fast integer type check.
     */
    public static function integer(mixed $value): bool
    {
        return $value === null || is_int($value);
    }

    /**
     * Fast numeric check.
     */
    public static function numeric(mixed $value): bool
    {
        return $value === null || is_numeric($value);
    }

    /**
     * Fast boolean check.
     */
    public static function boolean(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        return in_array($value, [true, false, 0, 1, '0', '1'], true);
    }

    /**
     * Fast array check.
     */
    public static function array(mixed $value): bool
    {
        return $value === null || is_array($value);
    }

    /**
     * Fast email check (basic pattern).
     */
    public static function email(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Fast min length/value check.
     */
    public static function min(mixed $value, int|float $min): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return mb_strlen($value) >= $min;
        }

        if (is_array($value)) {
            return count($value) >= $min;
        }

        if (is_numeric($value)) {
            return (float) $value >= $min;
        }

        return true;
    }

    /**
     * Fast max length/value check.
     */
    public static function max(mixed $value, int|float $max): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return mb_strlen($value) <= $max;
        }

        if (is_array($value)) {
            return count($value) <= $max;
        }

        if (is_numeric($value)) {
            return (float) $value <= $max;
        }

        return true;
    }

    /**
     * Fast URL check.
     */
    public static function url(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Fast IP check.
     */
    public static function ip(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Fast JSON check.
     */
    public static function json(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Validate a field using fast-path rules when possible.
     *
     * @param array<string, mixed> $data
     * @param list<string> $rules
     * @return array<string, string>|null Returns errors or null if valid
     */
    public static function validateField(string $field, array $data, array $rules): ?array
    {
        $value = $data[$field] ?? null;
        $errors = [];

        foreach ($rules as $rule) {
            $ruleName = $rule;
            $params = [];

            if (str_contains($rule, ':')) {
                [$ruleName, $paramStr] = explode(':', $rule, 2);
                $params = explode(',', $paramStr);
            }

            $isValid = match ($ruleName) {
                'required' => self::required($value),
                'string' => self::string($value),
                'integer', 'int' => self::integer($value),
                'numeric' => self::numeric($value),
                'boolean', 'bool' => self::boolean($value),
                'array' => self::array($value),
                'email' => self::email($value),
                'url' => self::url($value),
                'ip' => self::ip($value),
                'json' => self::json($value),
                'min' => isset($params[0]) ? self::min($value, (float) $params[0]) : true,
                'max' => isset($params[0]) ? self::max($value, (float) $params[0]) : true,
                default => null, // Not a fast-path rule
            };

            // If null, rule is not supported by fast-path
            if ($isValid === null) {
                return null;
            }

            if (!$isValid) {
                $errors[$ruleName] = $ruleName;
            }
        }

        return empty($errors) ? null : $errors;
    }
}
