<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::EXTENSIONS)]
final class ExtensionsRule implements RuleInterface
{
    /** @var list<string> */
    private array $extensions;

    public function __construct(string ...$extensions)
    {
        $this->extensions = array_values(array_map('strtolower', $extensions));
    }

    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        if ($value === null) {
            return null;
        }

        $path = $this->getPath($value);
        if ($path === null) {
            return ['rule' => 'extensions', 'parameters' => ['values' => implode(', ', $this->extensions)]];
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (!in_array($extension, $this->extensions, true)) {
            return ['rule' => 'extensions', 'parameters' => ['values' => implode(', ', $this->extensions)]];
        }

        return null;
    }

    private function getPath(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if ($value instanceof \SplFileInfo) {
            return $value->getPathname();
        }

        return null;
    }
}
