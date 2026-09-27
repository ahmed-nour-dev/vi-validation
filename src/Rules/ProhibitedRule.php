<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Execution\ValidationContext;

/**
 * The field under validation must be empty or not present.
 */
#[RuleName(RuleId::PROHIBITED)]
final class ProhibitedRule implements RuleInterface
{
    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && \Vi\Validation\Execution\Emptiness::isBlankString($value)) {
            return null;
        }

        if (is_array($value) && $value === []) {
            return null;
        }

        return ['rule' => 'prohibited'];
    }
}
