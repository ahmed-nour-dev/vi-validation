<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Execution\ValidationContext;

/**
 * The integer value must have at most :max digits (Laravel `max_digits`).
 */
#[RuleName(RuleId::MAX_DIGITS)]
final class MaxDigitsRule implements RuleInterface
{
    public function __construct(private readonly int $max)
    {
    }

    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        if (!is_scalar($value) || preg_match('/[^0-9]/', (string) $value) === 1 || strlen((string) $value) > $this->max) {
            return ['rule' => 'max_digits', 'params' => ['max' => $this->max]];
        }

        return null;
    }
}
