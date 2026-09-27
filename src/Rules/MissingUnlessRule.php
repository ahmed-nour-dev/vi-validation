<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::MISSING_UNLESS)]
final class MissingUnlessRule implements RuleInterface
{
    private string $otherField;
    /** @var list<mixed> */
    private array $values;

    public function __construct(string $otherField, mixed $value)
    {
        $this->otherField = $otherField;
        // One dependent value or several (Laravel: `rule:other,v1,v2,...`).
        $this->values = is_array($value) ? array_values($value) : [$value];
    }

    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        $otherValue = $context->getValue($this->otherField);

        if (DependentValues::matches($otherValue, $this->values)) {
            return null;
        }

        if ($context->hasValue($field)) {
            return [
                'rule' => 'missing_unless',
                'parameters' => [
                    'other' => $this->otherField,
                    'value' => DependentValues::describe($this->values),
                ],
            ];
        }

        return null;
    }
}
