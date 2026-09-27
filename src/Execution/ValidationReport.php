<?php

declare(strict_types=1);

namespace Vi\Validation\Execution;

/**
 * Aggregate outcome of validating a (possibly huge) batch of rows with
 * SchemaValidator::report().
 *
 * Every row is counted, but only the first $maxStoredFailures failed rows are retained (as
 * ValidationFailure objects with their index/key). So memory is bounded by that sample,
 * independent of how many rows are processed or how many fail. Per-field error counts are
 * aggregated over *all* rows.
 */
final class ValidationReport
{
    /**
     * @param list<ValidationFailure> $failures
     * @param array<string, int> $errorCountsByField
     */
    public function __construct(
        public readonly int $rowsProcessed,
        public readonly int $failedRows,
        public readonly int $errorCount,
        public readonly array $failures,
        public readonly array $errorCountsByField,
        public readonly int $maxStoredFailures,
    ) {
    }

    public function passedRows(): int
    {
        return $this->rowsProcessed - $this->failedRows;
    }

    public function allValid(): bool
    {
        return $this->failedRows === 0;
    }

    /**
     * Whether some failed rows were counted but not stored because the sample was full.
     */
    public function isTruncated(): bool
    {
        return $this->failedRows > count($this->failures);
    }

    /**
     * @return array{rows_processed: int, passed_rows: int, failed_rows: int, error_count: int, stored_failures: int, truncated: bool, error_counts_by_field: array<string, int>}
     */
    public function summary(): array
    {
        return [
            'rows_processed' => $this->rowsProcessed,
            'passed_rows' => $this->passedRows(),
            'failed_rows' => $this->failedRows,
            'error_count' => $this->errorCount,
            'stored_failures' => count($this->failures),
            'truncated' => $this->isTruncated(),
            'error_counts_by_field' => $this->errorCountsByField,
        ];
    }
}
