<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::ACCEPTED_IF)]
final class AcceptedIfRule implements RuleInterface
{
    private const ACCEPTABLE = ['yes', 'on', '1', 1, true, 'true'];

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

        if (!DependentValues::matches($otherValue, $this->values)) {
            return null;
        }

        if ($value === null || !in_array($value, self::ACCEPTABLE, true)) {
            return [
                'rule' => 'accepted_if',
                'parameters' => [
                    'other' => $this->otherField,
                    'value' => DependentValues::describe($this->values),
                ],
            ];
        }

        return null;
    }
}
