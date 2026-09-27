<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Execution\ValidationContext;

/**
 * Laravel `present_with_all:a,b,...`: the field must be present (it may be empty) when all of the
 * other fields are present.
 */
#[RuleName(RuleId::PRESENT_WITH_ALL)]
final class PresentWithAllRule implements RuleInterface
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

        $triggered = $this->otherFields !== [] && $present === count($this->otherFields);

        if ($triggered && !$context->hasValue($field)) {
            return ['rule' => 'present_with_all', 'params' => ['values' => implode(', ', $this->otherFields)]];
        }

        return null;
    }
}
