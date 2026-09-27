<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::IN)]
final class InRule implements RuleInterface
{
    /** @var list<string> */
    private array $values;

    /** @param list<string> $values */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    private bool $arrayContext = false;

    /**
     * Set when the field also has the `array` rule (Laravel checks each element then).
     */
    public function setArrayContext(bool $arrayContext): void
    {
        $this->arrayContext = $arrayContext;
    }

    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            // Laravel: with an `array` rule on the field every element must be in the list;
            // otherwise an array never matches.
            if (!$this->arrayContext) {
                return ['rule' => 'in'];
            }
            foreach ($value as $item) {
                if (!is_scalar($item) || !in_array((string) $item, $this->values, true)) {
                    return ['rule' => 'in'];
                }
            }

            return null;
        }

        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return ['rule' => 'in'];
        }

        if (!in_array((string) $value, $this->values, true)) {
            return ['rule' => 'in'];
        }

        return null;
    }
}
