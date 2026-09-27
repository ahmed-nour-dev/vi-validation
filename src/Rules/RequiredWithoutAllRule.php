<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::REQUIRED_WITHOUT_ALL)]
final class RequiredWithoutAllRule implements RuleInterface
{
    /** @var list<string> */
    private array $otherFields;

    /**
     * @param list<string> $otherFields
     */
    public function __construct(array $otherFields)
    {
        $this->otherFields = $otherFields;
    }

    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        // Check if ALL of the other fields are absent
        foreach ($this->otherFields as $otherField) {
            $otherValue = $context->getValue($otherField);
            if (!$this->isEmpty($otherValue)) {
                // At least one is present, this field is not required
                return null;
            }
        }

        // All other fields are absent, so this field is required
        if ($this->isEmpty($value)) {
            return [
                'rule' => 'required_without_all',
                'params' => [
                    'values' => implode(', ', $this->otherFields),
                ],
            ];
        }

        return null;
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value) && \Vi\Validation\Execution\Emptiness::isBlankString($value)) {
            return true;
        }

        if (is_array($value) && $value === []) {
            return true;
        }

        return false;
    }
}
