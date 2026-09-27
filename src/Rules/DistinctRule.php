<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Execution\ValidationContext;

/**
 * When validating arrays, the field must not have any duplicate values.
 */
#[RuleName(RuleId::DISTINCT)]
final class DistinctRule implements RuleInterface
{
    private bool $strict;
    private bool $ignoreCase;

    public function __construct(bool $strict = false, bool $ignoreCase = false)
    {
        $this->strict = $strict;
        $this->ignoreCase = $ignoreCase;
    }

    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        if ($value === null || !is_array($value)) {
            return null;
        }

        $values = $value;

        // Normalize values if ignore_case
        if ($this->ignoreCase) {
            $values = array_map(function ($v) {
                return is_string($v) ? strtolower($v) : $v;
            }, $values);
        }

        // Check for duplicates. array_unique()'s SORT_REGULAR mode still uses loose
        // comparison (e.g. 1 == '1'), so "strict" needs a real ===-based pass to match
        // Laravel's in_array($value, $data, true) semantics.
        if ($this->strict) {
            $unique = [];
            foreach ($values as $key => $v) {
                foreach ($unique as $existing) {
                    if ($v === $existing) {
                        continue 2;
                    }
                }
                $unique[$key] = $v;
            }
        } elseif ($this->allScalar($values)) {
            $unique = array_unique($values);
        } else {
            // array_unique() string-casts elements ("Array to string conversion" for nested
            // arrays). Compare scalars exactly as it would (by string) and nested arrays/objects
            // by their serialized form.
            $unique = [];
            foreach ($values as $v) {
                $unique[is_scalar($v) || $v === null ? 's:' . $v : 'o:' . serialize($v)] = true;
            }
        }

        if (count($unique) !== count($values)) {
            return ['rule' => 'distinct'];
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private function allScalar(array $values): bool
    {
        foreach ($values as $v) {
            if ($v !== null && !is_scalar($v)) {
                return false;
            }
        }

        return true;
    }
}
