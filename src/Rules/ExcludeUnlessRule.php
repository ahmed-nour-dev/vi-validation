<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::EXCLUDE_UNLESS)]
final class ExcludeUnlessRule implements RuleInterface
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

    public function shouldExclude(ValidationContext $context): bool
    {
        return !DependentValues::matches($context->getValue($this->otherField), $this->values);
    }

    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        return null;
    }
}
