<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::MISSING_WITH)]
final class MissingWithRule implements RuleInterface
{
    /** @var list<string> */
    private array $others;

    public function __construct(string ...$others)
    {
        $this->others = array_values($others);
    }

    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        $present = false;
        foreach ($this->others as $other) {
            if ($context->hasValue($other)) {
                $present = true;
                break;
            }
        }

        if (!$present) {
            return null;
        }

        if ($context->hasValue($field)) {
            return [
                'rule' => 'missing_with',
                'parameters' => [
                    'values' => implode(', ', $this->others),
                ],
            ];
        }

        return null;
    }
}
