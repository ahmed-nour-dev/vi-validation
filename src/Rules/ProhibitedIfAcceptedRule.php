<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Execution\Emptiness;
use Vi\Validation\Execution\ValidationContext;

/**
 * Laravel `prohibited_if_accepted:other`: the field is prohibited when the other field is accepted.
 */
#[RuleName(RuleId::PROHIBITED_IF_ACCEPTED)]
final class ProhibitedIfAcceptedRule implements RuleInterface
{
    private const TRIGGER = ['yes', 'on', '1', 1, true, 'true'];

    public function __construct(private readonly string $otherField)
    {
    }

    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        if (!in_array($context->getValue($this->otherField), self::TRIGGER, true)) {
            return null;
        }

        if (!Emptiness::isEmpty($value)) {
            return ['rule' => 'prohibited_if_accepted', 'params' => ['other' => $this->otherField]];
        }

        return null;
    }
}
