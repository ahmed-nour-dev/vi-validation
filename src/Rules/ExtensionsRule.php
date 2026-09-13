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

        $extension = $this->getExtension($value);

        if ($extension === null || !in_array($extension, $this->extensions, true)) {
            return ['rule' => 'extensions', 'parameters' => ['values' => implode(', ', $this->extensions)]];
        }

        return null;
    }

    /**
     * An uploaded file's real path is a temp filename with no meaningful extension
     * ('/tmp/phpXXXXXX'); the extension that matters is the client-provided original
     * filename's, matching Laravel's validateExtensions() (uses getClientOriginalExtension()).
     */
    private function getExtension(mixed $value): ?string
    {
        if (is_object($value) && method_exists($value, 'getClientOriginalExtension')) {
            /** @var string $clientExtension */
            $clientExtension = $value->getClientOriginalExtension();
            return strtolower($clientExtension);
        }

        $path = $this->getPath($value);

        return $path === null ? null : strtolower(pathinfo($path, PATHINFO_EXTENSION));
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
