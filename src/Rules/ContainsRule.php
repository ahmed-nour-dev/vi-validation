<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Execution\ValidationContext;

/**
 * The array must contain every listed value (Laravel `contains`, loose comparison).
 */
#[RuleName(RuleId::CONTAINS)]
final class ContainsRule implements RuleInterface
{
    /**
     * @param list<string> $values
     */
    public function __construct(private readonly array $values)
    {
    }

    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        if (!is_array($value)) {
            return ['rule' => 'contains', 'params' => ['values' => implode(', ', $this->values)]];
        }

        foreach ($this->values as $needle) {
            if (!in_array($needle, $value)) {
                return ['rule' => 'contains', 'params' => ['values' => implode(', ', $this->values)]];
            }
        }

        return null;
    }
}
