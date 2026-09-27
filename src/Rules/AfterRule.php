<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use DateTimeImmutable;
use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::AFTER)]
final class AfterRule implements RuleInterface
{
    private string $dateOrField;

    public function __construct(string $dateOrField)
    {
        $this->dateOrField = $dateOrField;
    }

    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        if ($value === null) {
            return null;
        }

        $date = $this->parseDate($value);
        if ($date === null) {
            return ['rule' => 'date'];
        }

        $compareDate = $this->getCompareDate($context);
        if ($compareDate === null) {
            return null;
        }

        if ($date <= $compareDate) {
            return [
                'rule' => 'after',
                'params' => ['date' => $this->dateOrField],
            ];
        }

        return null;
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTime) {
            return DateTimeImmutable::createFromMutable($value);
        }

        if (is_string($value)) {
            try {
                return new DateTimeImmutable($value);
            } catch (\Exception | \ValueError) { // ValueError: e.g. NUL bytes in the string
                return null;
            }
        }

        return null;
    }

    private function getCompareDate(ValidationContext $context): ?DateTimeImmutable
    {
        // First check if it's a field reference
        $fieldValue = $context->getValue($this->dateOrField);
        if ($fieldValue !== null) {
            return $this->parseDate($fieldValue);
        }

        // Try to parse as a date string
        return $this->parseDate($this->dateOrField);
    }
}
