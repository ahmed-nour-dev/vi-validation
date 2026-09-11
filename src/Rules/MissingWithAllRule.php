<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::MISSING_WITH_ALL)]
final class MissingWithAllRule implements RuleInterface
{
    /** @var list<string> */
    private array $others;

    public function __construct(string ...$others)
    {
        $this->others = array_values($others);
    }

    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        $allPresent = true;
        foreach ($this->others as $other) {
            if (!$context->hasValue($other)) {
                $allPresent = false;
                break;
            }
        }

        if (!$allPresent) {
            return null;
        }

        if ($context->hasValue($field)) {
            return [
                'rule' => 'missing_with_all',
                'parameters' => [
                    'values' => implode(', ', $this->others),
                ],
            ];
        }

        return null;
    }
}
