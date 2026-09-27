<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::NOT_IN)]
final class NotInRule implements RuleInterface
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
            // Laravel defines not_in as exactly !in: with an `array` rule on the field it fails
            // only when *every* element is in the list (and none is itself an array); without
            // one an array never matches `in`, so `not_in` passes.
            if ($this->arrayContext && $value !== []) {
                foreach ($value as $item) {
                    if (!is_scalar($item) || !in_array((string) $item, $this->values, true)) {
                        return null;
                    }
                }

                return ['rule' => 'not_in'];
            }

            return null;
        }

        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return null;
        }

        if (in_array((string) $value, $this->values, true)) {
            return ['rule' => 'not_in'];
        }

        return null;
    }
}
