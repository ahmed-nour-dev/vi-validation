<?php

declare(strict_types=1);

namespace Vi\Validation;

use Vi\Validation\Execution\CompiledSchema;
use Vi\Validation\Laravel\RuleSetCompiler;
use Vi\Validation\Rules\RuleRegistry;
use Vi\Validation\Schema\SchemaBuilder;

final class Validator
{
    public static function schema(): SchemaBuilder
    {
        return new SchemaBuilder();
    }

    /**
     * Compile a Laravel-style rules array (`['email' => 'required|email']`) into a schema.
     *
     * @param array<string, mixed> $rules
     */
    public static function fromRules(array $rules, ?RuleRegistry $registry = null): CompiledSchema
    {
        if ($registry === null) {
            $registry = new RuleRegistry();
            $registry->registerBuiltInRules();
        }

        return (new RuleSetCompiler($registry))->compile($rules);
    }
}
