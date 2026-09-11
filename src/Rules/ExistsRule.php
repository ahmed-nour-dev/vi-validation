<?php

declare(strict_types=1);

namespace Vi\Validation\Rules;

use Vi\Validation\Execution\ValidationContext;

#[RuleName(RuleId::EXISTS)]
final class ExistsRule implements RuleInterface
{
    private string $table;
    private string $column;
    private ?string $connection;
    /** @var array<string, mixed> */
    private array $extraConstraints;

    /**
     * @param array<string, mixed> $extraConstraints
     */
    public function __construct(string $table, string $column = 'id', array $extraConstraints = [], ?string $connection = null)
    {
        $this->table = $table;
        $this->column = $column;
        $this->extraConstraints = $extraConstraints;
        $this->connection = $connection;
    }

    public function validate(mixed $value, string $field, ValidationContext $context): ?array
    {
        if ($value === null) {
            return null;
        }

        // Get DatabaseValidator from context if available
        // Note: We'll need to update ValidationContext to hold a DatabaseValidator instance
        $dbValidator = $context->getDatabaseValidator();

        if ($dbValidator === null) {
            return null; // Or throw an exception? Let's assume it fails silently if not configured
        }

        if (!$dbValidator->exists($this->table, $this->column, $value, $this->extraConstraints, $this->connection)) {
            return ['rule' => 'exists'];
        }

        return null;
    }
}
