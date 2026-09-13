<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Benchmarks;

/**
 * A named, fixed benchmark scenario: one Laravel-style rules array shared verbatim by every
 * variant (the Laravel validator, and vi/validation via LaravelRuleParser) so there is exactly
 * one rule definition to keep in sync, plus a deterministic row generator (no PRNG — every row
 * is a pure function of its index, so results are byte-for-byte reproducible across runs and
 * across the subprocess-per-measurement isolation the runner uses).
 */
final class Scenario
{
    /**
     * @param array<string, string> $rules
     * @param \Closure(int $index): array<string, mixed> $rowGenerator
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $rules,
        public readonly \Closure $rowGenerator,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ($this->rowGenerator)($i);
        }

        return $rows;
    }
}
