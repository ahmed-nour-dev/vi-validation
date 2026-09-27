<?php

declare(strict_types=1);

namespace Vi\Validation\Execution;

use Vi\Validation\Compilation\SchemaFingerprint;
use Vi\Validation\Schema\FieldDefinition;

final class CompiledSchema
{
    /** @var list<CompiledField> */
    private array $fields;

    /** @var array<string, mixed> */
    private array $rulesArray;

    private ?ValidatorEngine $engine = null;

    private ?SchemaFingerprint $fingerprint = null;

    private ?float $compileTimeMs = null;

    /**
     * @param list<CompiledField> $fields
     * @param array<string, mixed> $rulesArray
     */
    private function __construct(array $fields, array $rulesArray = [])
    {
        $this->fields = $fields;
        $this->rulesArray = $rulesArray;
    }

    /**
     * @param array<string, FieldDefinition> $fieldDefinitions
     * @param array<string, mixed> $rulesArray
     */
    public static function fromFieldDefinitions(array $fieldDefinitions, array $rulesArray = []): self
    {
        $compiled = [];

        foreach ($fieldDefinitions as $name => $definition) {
            $compiled[] = CompiledField::fromFieldDefinition($definition);
        }

        return new self($compiled, $rulesArray);
    }
    
    /**
     * @return array<string, mixed>
     */
    public function getRulesArray(): array
    {
        return $this->rulesArray;
    }

    /**
     * @return list<CompiledField>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * How long building this schema took (rule parsing + field compilation), in ms, when
     * known. Diagnostic only.
     */
    public function getCompileTimeMs(): ?float
    {
        return $this->compileTimeMs;
    }

    /**
     * @internal Recorded by SchemaBuilder / RuleSetCompiler.
     */
    public function recordCompileTime(float $milliseconds): void
    {
        $this->compileTimeMs = $milliseconds;
    }

    /**
     * Deterministic identity of this schema's validation semantics, computed once and
     * memoized. See SchemaFingerprint for what is (and isn't) part of it.
     */
    public function fingerprint(): SchemaFingerprint
    {
        return $this->fingerprint ??= SchemaFingerprint::of($this);
    }

    /**
     * Validate data against this schema.
     *
     * @param array<string, mixed> $data
     */
    public function validate(array $data): ValidationResult
    {
        if ($this->engine === null) {
            $this->engine = new ValidatorEngine();
        }

        return $this->engine->validate($this, $data);
    }
}
