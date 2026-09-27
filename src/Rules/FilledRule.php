<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Execution\ValidationContext;

/**
 * The field under validation must not be empty when it is present.
 */
#[RuleName(RuleId::FILLED)]
final class FilledRule implements RuleInterface
{
    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        // If the field is not present (null), the validation passes
        if ($value === null) {
            return null;
        }

        // If the field is present but empty, fail validation
        if (is_string($value) && \Vi\Validation\Execution\Emptiness::isBlankString($value)) {
            return ['rule' => 'filled'];
        }

        if (is_array($value) && $value === []) {
            return ['rule' => 'filled'];
        }

        return null;
    }
}
