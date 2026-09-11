<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::PROHIBITS)]
final class ProhibitsRule implements RuleInterface
{
    /** @var list<string> */
    private array $fields;

    public function __construct(string ...$fields)
    {
        $this->fields = array_values($fields);
    }

    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        // If this field is missing or null, it doesn't prohibit others
        if (!$context->hasValue($field) || $value === null) {
            return null;
        }

        foreach ($this->fields as $prohibitedField) {
            if ($context->hasValue($prohibitedField)) {
                return [
                    'rule' => 'prohibits',
                    'parameters' => [
                        'fields' => implode(', ', $this->fields),
                    ],
                ];
            }
        }

        return null;
    }
}
