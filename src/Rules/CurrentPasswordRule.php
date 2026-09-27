<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::CURRENT_PASSWORD)]
final class CurrentPasswordRule implements RuleInterface
{
    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        if ($value === null || !is_string($value)) {
            return null;
        }

        $hasher = $context->getPasswordHasher();

        // Fail closed, like Laravel (a guest never passes current_password).
        if ($hasher === null) {
            return ['rule' => 'current_password'];
        }

        if (!$hasher->check($value)) {
            return ['rule' => 'current_password'];
        }

        return null;
    }
}
