<?php

declare(strict_types=1);

namespace Vi\Validation\Execution;

/**
 * Laravel's notion of an "empty" value, in one place.
 *
 * Laravel treats a string that is empty *after trim()* as empty: `required`/`filled` reject
 * "   " and "\t\n", `prohibited` accepts them, and non-implicit rules (email, integer, min, ...)
 * are skipped for them. The characters are PHP trim()'s defaults: space, \t, \n, \r, \0, \x0B.
 */
final class Emptiness
{
    /** PHP trim()'s default character list. */
    public const TRIM_CHARS = " \t\n\r\0\x0B";

    /** Lookup of TRIM_CHARS, so the common case (non-blank first byte) costs one isset(). */
    public const BLANK_FIRST = [' ' => true, "\t" => true, "\n" => true, "\r" => true, "\0" => true, "\x0B" => true];

    public static function isEmpty(mixed $value): bool
    {
        return $value === null
            || $value === []
            || (is_string($value) && self::isBlankString($value))
            || ($value instanceof \Countable && count($value) === 0);
    }

    /**
     * trim($value) === '' without allocating a trimmed copy.
     */
    public static function isBlankString(string $value): bool
    {
        return $value === ''
            || (isset(self::BLANK_FIRST[$value[0]]) && strspn($value, self::TRIM_CHARS) === strlen($value));
    }

    /**
     * The same check as PHP source, for generated native code ($var must be a variable).
     */
    public static function nativeExpression(string $var): string
    {
        return "({$var} === null || {$var} === [] || (is_string({$var}) && ({$var} === ''"
            . " || (isset(\\Vi\\Validation\\Execution\\Emptiness::BLANK_FIRST[{$var}[0]])"
            . " && strspn({$var}, \\Vi\\Validation\\Execution\\Emptiness::TRIM_CHARS) === strlen({$var})))))";
    }
}
