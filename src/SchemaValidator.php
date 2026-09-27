<?php

declare(strict_types=1);

namespace Vi\Validation;

use Generator;
use Vi\Validation\Execution\CompiledSchema;
use Vi\Validation\Execution\ValidatorEngine;
use Vi\Validation\Execution\ValidationFailure;
use Vi\Validation\Execution\ValidationResult;
use Vi\Validation\Schema\SchemaBuilder;

final class SchemaValidator
{
    private CompiledSchema $schema;
    private ValidatorEngine $engine;
    private \Vi\Validation\Compilation\ValidatorCompiler $compiler;
    private ?\Vi\Validation\Messages\MessageResolver $messageResolver;

    public function __construct(
        CompiledSchema $schema,
        ?ValidatorEngine $engine = null,
        ?\Vi\Validation\Compilation\ValidatorCompiler $compiler = null,
        ?\Vi\Validation\Messages\MessageResolver $messageResolver = null
    ) {
        $this->schema = $schema;
        $this->engine = $engine ?? new ValidatorEngine();
        $this->compiler = $compiler ?? new \Vi\Validation\Compilation\ValidatorCompiler();
        $this->messageResolver = $messageResolver;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $rulesArray
     */
    public static function build(callable $definition, array $config = [], array $rulesArray = []): self
    {
        $builder = new SchemaBuilder();
        if (!empty($rulesArray)) {
            $builder->setRulesArray($rulesArray);
        }
        $definition($builder);

        $signingKey = $config['security']['signing_key'] ?? null;
        $compiler = new \Vi\Validation\Compilation\ValidatorCompiler(
            null,
            (bool) ($config['compilation']['precompile'] ?? false),
            $config['compilation']['cache_path'] ?? null,
            is_string($signingKey) && $signingKey !== '' ? $signingKey : null
        );

        return new self($builder->compile(), null, $compiler);
    }

    public function getSchema(): CompiledSchema
    {
        return $this->schema;
    }

    public function getEngine(): ValidatorEngine
    {
        return $this->engine;
    }

    public function getCompiler(): \Vi\Validation\Compilation\ValidatorCompiler
    {
        return $this->compiler;
    }

    public function getMessageResolver(): ?\Vi\Validation\Messages\MessageResolver
    {
        return $this->messageResolver;
    }

    /**
     * Describe how this schema executes and why: fingerprint, field/rule counts, per-field
     * native support (with the reason for every unsupported rule), artifact status/path and
     * the chosen strategy. Read-only: it never generates, loads or executes an artifact, and
     * it contains no rule parameters or data. Off the hot path; costs nothing unless called.
     */
    public function diagnostics(): \Vi\Validation\Diagnostics\SchemaDiagnostics
    {
        return \Vi\Validation\Diagnostics\SchemaInspector::inspect(
            $this->schema,
            $this->compiler,
            $this->nativeResolved ? $this->cachedNativeValidator !== null : null
        );
    }

    /**
     * Resolve the execution strategy now instead of on the first validate() call: load the
     * schema's native artifact, generating it first when `precompile` is enabled. Call it at
     * boot/deploy time (or when a queue worker starts) so the first real row pays nothing.
     *
     * Returns whether validation will run natively.
     */
    public function warm(): bool
    {
        return $this->usesNative();
    }

    private ?\Vi\Validation\Execution\NativeValidator $cachedNativeValidator = null;

    private bool $nativeResolved = false;

    /**
     * @param array<string, mixed> $data
     */
    public function validate(array $data): ValidationResult
    {
        if ($this->cachedNativeValidator !== null) {
            return $this->cachedNativeValidator->validate($data);
        }

        // Look for a native precompiled validator (highest speed) exactly once per instance:
        // the lookup hashes the schema and touches the filesystem, which must never happen
        // per row. Loaded closures are additionally memoized per process by the repository.
        if (!$this->nativeResolved && $this->usesNative()) {
            /** @var \Vi\Validation\Execution\NativeValidator $native */
            $native = $this->cachedNativeValidator;
            return $native->validate($data);
        }

        return $this->engine->validate($this->schema, $data);
    }

    /**
     * Whether validate() runs a native precompiled closure for this schema (resolving the
     * native artifact if that hasn't happened yet).
     */
    public function usesNative(): bool
    {
        if (!$this->nativeResolved) {
            $this->nativeResolved = true;
            $closure = $this->compiler->loadNativeFor($this->schema);

            // Compile-on-first-use: generate the artifact once, then load it.
            if ($closure === null && $this->compiler->isPrecompileEnabled() && $this->compiler->writeNativeFor($this->schema) !== null) {
                $closure = $this->compiler->loadNativeFor($this->schema);
            }

            if ($closure !== null) {
                $this->cachedNativeValidator = new \Vi\Validation\Execution\NativeValidator($closure, $this->messageResolver);
            }
        }

        return $this->cachedNativeValidator !== null;
    }

    /**
     * Validate multiple rows and return all results at once.
     *
     * WARNING: This method materializes all results in memory. For large datasets
     * (10,000+ rows), use stream() or each() instead to avoid memory exhaustion.
     *
     * @param iterable<array<string, mixed>> $rows
     * @return list<ValidationResult>
     */
    public function validateMany(iterable $rows): array
    {
        $results = [];

        foreach ($rows as $row) {
            $results[] = $this->validate($row);
        }

        return $results;
    }

    /**
     * Stream-validate rows using a generator for memory-efficient batch processing.
     *
     * Rows are pulled from $rows one at a time and each result is yielded before the next row
     * is read, so memory stays bounded by one row + its result regardless of dataset size -
     * provided the caller doesn't keep the results. Nothing is materialized: arrays,
     * generators, iterators, database cursors and Laravel LazyCollections are all consumed
     * lazily. Each ValidationResult is an independent immutable value; later rows never
     * change an earlier result. Breaking out of the loop early is safe: the validator holds no
     * per-stream state and can be reused immediately.
     *
     * Usage:
     * ```php
     * foreach ($validator->stream($rows) as $index => $result) {
     *     if (!$result->isValid()) {
     *         // Handle error
     *     }
     * }
     * ```
     *
     * @param iterable<array-key, array<string, mixed>> $rows
     * @param bool $preserveKeys Yield the source's own keys (array keys, or whatever a generator
     *        yields, e.g. CSV line numbers or primary keys) instead of a zero-based position.
     * @return Generator<array-key, ValidationResult>
     */
    public function stream(iterable $rows, bool $preserveKeys = false): Generator
    {
        $index = 0;

        foreach ($rows as $key => $row) {
            yield ($preserveKeys ? $key : $index) => $this->validate($row);
            $index++;
        }
    }

    /**
     * Validate rows with a callback, processing each result immediately.
     *
     * This method never stores results in memory, making it ideal for
     * fire-and-forget validation of large datasets.
     *
     * Usage:
     * ```php
     * $validator->each($rows, function (ValidationResult $result, int $index) {
     *     if (!$result->isValid()) {
     *         Log::error("Row $index failed", $result->errors());
     *     }
     * });
     * ```
     *
     * @param iterable<array-key, array<string, mixed>> $rows
     * @param callable(ValidationResult $result, int|string $index): void $callback
     * @param bool $preserveKeys Pass the source's own key instead of a zero-based position.
     */
    public function each(iterable $rows, callable $callback, bool $preserveKeys = false): void
    {
        $index = 0;

        foreach ($rows as $key => $row) {
            $callback($this->validate($row), $preserveKeys ? $key : $index);
            $index++;
        }
    }

    /**
     * Validate rows and collect only failures, streaming through all data.
     *
     * Memory-efficient way to find all validation errors without storing
     * successful validations. Useful for batch import error reporting.
     *
     * @param iterable<array-key, array<string, mixed>> $rows
     * @param bool $preserveKeys Key failures by the source's own keys instead of zero-based position.
     * @return Generator<array-key, ValidationResult> Yields only failed validation results with their original index
     */
    public function failures(iterable $rows, bool $preserveKeys = false): Generator
    {
        $index = 0;

        foreach ($rows as $key => $row) {
            $result = $this->validate($row);

            if (!$result->isValid()) {
                yield ($preserveKeys ? $key : $index) => $result;
            }

            $index++;
        }
    }

    /**
     * Validate rows until the first failure, then stop.
     *
     * Useful for fail-fast validation where you want to abort on first error.
     *
     * @param iterable<array<string, mixed>> $rows
     * @return ValidationResult|null The first failed result, or null if all pass
     */
    public function firstFailure(iterable $rows): ?ValidationResult
    {
        return $this->firstFailureWithIndex($rows)?->result;
    }

    /**
     * Validate rows until the first failure, then stop, returning the failed result
     * together with the row's position and source key.
     *
     * Unlike firstFailure(), the caller learns *which* row failed without re-validating
     * anything - essential for import error reports ("row 1,284: email is invalid").
     *
     * Usage:
     * ```php
     * if ($failure = $validator->firstFailureWithIndex($rows)) {
     *     echo "Row {$failure->index} failed: " . $failure->result->first();
     * }
     * ```
     *
     * @param iterable<array-key, array<string, mixed>> $rows
     * @return ValidationFailure|null The first failure, or null if every row passes
     */
    public function firstFailureWithIndex(iterable $rows): ?ValidationFailure
    {
        $index = 0;

        foreach ($rows as $key => $row) {
            $result = $this->validate($row);

            if (!$result->isValid()) {
                return new ValidationFailure($index, $key, $result);
            }

            $index++;
        }

        return null;
    }

    /**
     * Check if all rows pass validation without storing results.
     *
     * Memory-efficient way to validate entire dataset. Stops at first failure.
     *
     * @param iterable<array<string, mixed>> $rows
     */
    public function allValid(iterable $rows): bool
    {
        return $this->firstFailure($rows) === null;
    }
}
