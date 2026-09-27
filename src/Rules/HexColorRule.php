<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Execution\ValidationContext;

/**
 * A hexadecimal color: #rgb, #rgba, #rrggbb or #rrggbbaa (Laravel `hex_color`).
 */
#[RuleName(RuleId::HEX_COLOR)]
final class HexColorRule implements RuleInterface
{
    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        if (!is_string($value) || preg_match('/^#(?:(?:[0-9a-f]{3}){1,2}|(?:[0-9a-f]{4}){1,2})$/i', $value) !== 1) {
            return ['rule' => 'hex_color'];
        }

        return null;
    }
}
