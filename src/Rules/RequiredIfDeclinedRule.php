<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Execution\Emptiness;
use Vi\Validation\Execution\ValidationContext;

/**
 * Laravel `required_if_declined:other`: the field is required when the other field is declined.
 */
#[RuleName(RuleId::REQUIRED_IF_DECLINED)]
final class RequiredIfDeclinedRule implements RuleInterface
{
    private const TRIGGER = ['no', 'off', '0', 0, false, 'false'];

    public function __construct(private readonly string $otherField)
    {
    }

    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        if (!in_array($context->getValue($this->otherField), self::TRIGGER, true)) {
            return null;
        }

        if (Emptiness::isEmpty($value)) {
            return ['rule' => 'required_if_declined', 'params' => ['other' => $this->otherField]];
        }

        return null;
    }
}
