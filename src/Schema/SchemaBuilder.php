<?php

declare(strict_types=1);

namespace Vi\Validation\Schema;

use Vi\Validation\Execution\CompiledSchema;

final class SchemaBuilder
{
    /** @var array<string, FieldDefinition> */
    private array $fields = [];

    /** @var array<string, mixed> */
    private array $rulesArray = [];

    public function field(string $name): FieldDefinition
    {
        if (!isset($this->fields[$name])) {
            $this->fields[$name] = new FieldDefinition($name, $this);
        }

        return $this->fields[$name];
    }

    /**
     * @param array<string, mixed> $rules
     */
    public function setRulesArray(array $rules): void
    {
        $this->rulesArray = $rules;
    }

    public function compile(): CompiledSchema
    {
        $start = hrtime(true);
        $schema = CompiledSchema::fromFieldDefinitions($this->fields, $this->rulesArray);
        $schema->recordCompileTime((hrtime(true) - $start) / 1e6);

        return $schema;
    }
}
