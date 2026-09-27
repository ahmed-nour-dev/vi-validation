<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use DateTimeImmutable;
use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::DATE)]
final class DateRule implements RuleInterface
{
    private ?string $format;

    public function __construct(?string $format = null)
    {
        $this->format = $format;
    }

    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        if ($value === null) {
            return null;
        }

        if ($this->format !== null) {
            // createFromFormat() throws ValueError on NUL bytes; never a valid formatted date.
            if (!is_string($value) || str_contains($value, "\0")) {
                return ['rule' => 'date'];
            }
            $date = DateTimeImmutable::createFromFormat($this->format, $value);
            if ($date === false || $date->format($this->format) !== $value) {
                return ['rule' => 'date'];
            }

            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return null;
        }

        // Laravel's validateDate(): strtotime() must accept it AND date_parse() must yield a
        // real calendar date (strtotime alone accepts e.g. "x", a military time zone).
        if ((!is_string($value) && !is_numeric($value)) || strtotime((string) $value) === false) {
            return ['rule' => 'date'];
        }

        $parsed = date_parse((string) $value);
        if (!is_int($parsed['month']) || !is_int($parsed['day']) || !is_int($parsed['year'])
            || !checkdate($parsed['month'], $parsed['day'], $parsed['year'])) {
            return ['rule' => 'date'];
        }

        return null;
    }
}
