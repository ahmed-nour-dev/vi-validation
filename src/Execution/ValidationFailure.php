<?php

declare(strict_types=1);

namespace Vi\Validation\Execution;

/**
 * A failed row from a batch/streaming validation, paired with where it came from.
 *
 * - $index is the zero-based position of the row in the iteration order of the input
 *   (the Nth row consumed, starting at 0), regardless of the input's own keys.
 * - $key is the key the input iterable itself produced for that row: the array key for
 *   an array, or whatever key a generator/iterator yielded (e.g. `yield $lineNo => $row`).
 *   For a plain list or a generator that yields without keys, $key === $index.
 *
 * Use $index for "row N of the upload" style reporting and $key when the source already
 * carries meaningful identifiers (CSV line numbers, database primary keys, etc.).
 */
final class ValidationFailure
{
    public function __construct(
        public readonly int $index,
        public readonly int|string $key,
        public readonly ValidationResult $result,
    ) {
    }

    /**
     * @return array<string, list<string>>
     */
    public function messages(): array
    {
        return $this->result->messages();
    }

    /**
     * @return array<string, list<array{rule: string, params: array<string, mixed>, message: string|null}>>
     */
    public function errors(): array
    {
        return $this->result->errors();
    }
}
