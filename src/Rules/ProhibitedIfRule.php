<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::PROHIBITED_IF)]
final class ProhibitedIfRule implements RuleInterface
{
    private string $otherField;

    /** @var list<mixed> */
    private array $values;

    /**
     * @param list<mixed> $values
     */
    public function __construct(string $otherField, array $values)
    {
        $this->otherField = $otherField;
        $this->values = $values;
    }

    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        $otherValue = $context->getValue($this->otherField);

        if (DependentValues::matches($otherValue, $this->values)) {
            if (!$this->isEmpty($value)) {
                return [
                    'rule' => 'prohibited_if',
                    'parameters' => [
                        'other' => $this->otherField,
                        'value' => implode(', ', $this->values),
                    ],
                ];
            }
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
