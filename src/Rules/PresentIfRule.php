<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Execution\ValidationContext;

/**
 * Laravel `present_if:other,value,...`: the field must be present (it may be empty) when the other
 * field equals one of the values.
 */
#[RuleName(RuleId::PRESENT_IF)]
final class PresentIfRule implements RuleInterface
{
    /**
     * @param list<mixed> $values
     */
    public function __construct(private readonly string $otherField, private readonly array $values)
    {
    }

    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        if (!DependentValues::matches($context->getValue($this->otherField), $this->values)) {
            return null;
        }

        if (!$context->hasValue($field)) {
            return [
                'rule' => 'present_if',
                'params' => ['other' => $this->otherField, 'value' => DependentValues::describe($this->values)],
            ];
        }

        return null;
    }
}
