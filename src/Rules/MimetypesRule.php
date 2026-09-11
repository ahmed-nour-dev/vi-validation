<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::MIMETYPES)]
final class MimetypesRule implements RuleInterface
{
    /** @var list<string> */
    private array $types;

    public function __construct(string ...$types)
    {
        $this->types = array_values($types);
    }

    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        // This rule expects a file path or a SplFileInfo object if handled by the engine
        // In this package, we assume file validation is performed on paths or SplFileInfo
        if ($value === null) {
            return null;
        }

        $path = $this->getPath($value);
        if ($path === null || !file_exists($path)) {
            return ['rule' => 'mimetypes', 'parameters' => ['values' => implode(', ', $this->types)]];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }
        $mimeType = finfo_file($finfo, $path);
        finfo_close($finfo);

        if (!in_array($mimeType, $this->types, true)) {
            return ['rule' => 'mimetypes', 'parameters' => ['values' => implode(', ', $this->types)]];
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
