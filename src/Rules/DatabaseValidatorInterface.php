<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

/**
 * Interface for database-backed validation rules.
 * Implement this interface to provide database connectivity to rules like 'exists' and 'unique'.
 */
interface DatabaseValidatorInterface
{
    /**
     * Check if a value exists in the database.
     *
     * @param string $table
     * @param string $column
     * @param mixed $value
     * @param array<string, mixed> $extraConstraints
     * @param string|null $connection
     * @return bool
     */
    public function exists(string $table, string $column, mixed $value, array $extraConstraints = [], ?string $connection = null): bool;

    /**
     * Check if a value is unique in the database.
     *
     * @param string $table
     * @param string $column
     * @param mixed $value
     * @param mixed $ignoreId
     * @param string $idColumn
     * @param array<string, mixed> $extraConstraints
     * @param string|null $connection
     * @return bool
     */
    public function unique(string $table, string $column, mixed $value, mixed $ignoreId = null, string $idColumn = 'id', array $extraConstraints = [], ?string $connection = null): bool;
}
