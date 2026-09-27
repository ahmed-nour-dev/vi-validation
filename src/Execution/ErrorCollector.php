<?php

declare(strict_types=1);

namespace Vi\Validation\Execution;

final class ErrorCollector
{
    /** @var array<string, list<array{rule: string, params: array<string, mixed>, message: string|null}>> */
    private array $errors = [];

    private int $errorCount = 0;

    private bool $countOnly = false;

    /** @var array<string, int> */
    private array $fieldCounts = [];

    /**
     * In count-only mode no error details are stored, only per-field counts.
     */
    public function setCountOnly(bool $countOnly): void
    {
        $this->countOnly = $countOnly;
    }

    public function isCountOnly(): bool
    {
        return $this->countOnly;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function add(string $field, string $rule, ?string $message = null, array $params = []): void
    {
        $this->errorCount++;

        if ($this->countOnly) {
            $this->fieldCounts[$field] = ($this->fieldCounts[$field] ?? 0) + 1;
            return;
        }

        $this->errors[$field][] = [
            'rule' => $rule,
            'params' => $params,
            'message' => $message,
        ];
    }

    public function reset(): void
    {
        $this->errors = [];
        $this->fieldCounts = [];
        $this->errorCount = 0;
    }

    public function hasErrors(): bool
    {
        return $this->errorCount > 0;
    }

    /**
     * Per-field error counts (only populated in count-only mode).
     *
     * @return array<string, int>
     */
    public function fieldCounts(): array
    {
        return $this->fieldCounts;
    }

    public function count(): int
    {
        return $this->errorCount;
    }

    /**
     * @return array<string, list<array{rule: string, params: array<string, mixed>, message: string|null}>>
     */
    public function all(): array
    {
        return $this->errors;
    }
}
