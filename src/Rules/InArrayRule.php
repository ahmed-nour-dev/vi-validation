<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Execution\DataHelper;
use Vi\Validation\Execution\ValidationContext;

/**
 * The value must be one of the values found at another (wildcard) path, e.g.
 * `in_array:allowed.*` (Laravel `in_array`, loose comparison).
 */
#[RuleName(RuleId::IN_ARRAY)]
final class InArrayRule implements RuleInterface
{
    public function __construct(private readonly string $otherPath)
    {
    }

    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        if (!in_array($value, $this->candidates($context->getData()))) {
            return ['rule' => 'in_array', 'params' => ['other' => $this->otherPath]];
        }

        return null;
    }

    /**
     * Every value in the data whose dotted key matches the pattern (`*` = any characters,
     * like Laravel's Str::is()), searched under the pattern's leading explicit path.
     *
     * @param array<array-key, mixed> $data
     * @return list<mixed>
     */
    private function candidates(array $data): array
    {
        $starAt = strpos($this->otherPath, '*');
        $prefix = rtrim($starAt === false ? $this->otherPath : substr($this->otherPath, 0, $starAt), '.');

        $root = $prefix === '' ? $data : DataHelper::get($data, $prefix);
        if ($starAt === false) {
            return DataHelper::has($data, $prefix) ? [$root] : [];
        }
        if (!is_array($root)) {
            return [];
        }

        $regex = '/^' . str_replace('\*', '.*', preg_quote($this->otherPath, '/')) . '$/Du';
        $matches = [];
        $this->collect($root, $prefix, $regex, $matches);

        return $matches;
    }

    /**
     * @param array<array-key, mixed> $node
     * @param list<mixed> $matches
     */
    private function collect(array $node, string $path, string $regex, array &$matches): void
    {
        foreach ($node as $key => $child) {
            $childPath = $path === '' ? (string) $key : $path . '.' . $key;
            if (is_array($child) && $child !== []) {
                $this->collect($child, $childPath, $regex, $matches);
            } elseif (preg_match($regex, $childPath) === 1) {
                $matches[] = $child;
            }
        }
    }
}
