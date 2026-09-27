<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Execution\ValidationContext;

/**
 * Laravel `present_with:a,b,...`: the field must be present (it may be empty) when any of the
 * other fields are present.
 */
#[RuleName(RuleId::PRESENT_WITH)]
final class PresentWithRule implements RuleInterface
{
    /**
     * @param list<string> $otherFields
     */
    public function __construct(private readonly array $otherFields)
    {
    }

    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        $present = 0;
        foreach ($this->otherFields as $other) {
            if ($context->hasValue($other)) {
                $present++;
            }
        }

        $triggered = $present > 0;

        if ($triggered && !$context->hasValue($field)) {
            return ['rule' => 'present_with', 'params' => ['values' => implode(', ', $this->otherFields)]];
        }

        return null;
    }
}
