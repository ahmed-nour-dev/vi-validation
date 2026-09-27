<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::REQUIRED_IF_ACCEPTED)]
final class RequiredIfAcceptedRule implements RuleInterface
{
    private const ACCEPTABLE = ['yes', 'on', '1', 1, true, 'true'];

    private string $otherField;

    public function __construct(string $otherField)
    {
        $this->otherField = $otherField;
    }

    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        $otherValue = $context->getValue($this->otherField);

        if ($otherValue !== null && in_array($otherValue, self::ACCEPTABLE, true)) {
            if (\Vi\Validation\Execution\Emptiness::isEmpty($value)) {
                return [
                    'rule' => 'required_if_accepted',
                    'parameters' => [
                        'other' => $this->otherField,
                    ],
                ];
            }
        }

        return null;
    }
}
