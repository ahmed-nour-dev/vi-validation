<?php

declare(strict_types=1);

namespace Vi\Validation\Laravel;

use Vi\Validation\Execution\CompiledSchema;
use Vi\Validation\Rules\IntegerTypeRule;
use Vi\Validation\Rules\NumericAwareInterface;
use Vi\Validation\Rules\NumericRule;
use Vi\Validation\Rules\RuleRegistry;
use Vi\Validation\Schema\SchemaBuilder;

/**
 * Builds a CompiledSchema from a Laravel-style rules array
 * (`['email' => 'required|email', 'age' => ['integer', 'min:18']]`).
 *
 * This is the single place rule strings are turned into a schema, so the factory, the
 * wrapper's conditional `sometimes()` rebuild and ahead-of-time precompilation always produce
 * the same schema (and therefore the same fingerprint / native artifact) for the same rules.
 * It applies Laravel's "numeric context": when a field has `integer` or `numeric`, size rules
 * such as `min`/`max`/`between` compare the value numerically instead of by string length.
 */
final class RuleSetCompiler
{
    private LaravelRuleParser $parser;

    public function __construct(?RuleRegistry $registry = null)
    {
        $this->parser = new LaravelRuleParser($registry);
    }

    /**
     * @param array<string, mixed> $rules
     */
    public function compile(array $rules): CompiledSchema
    {
        $start = hrtime(true);
        $builder = new SchemaBuilder();
        $builder->setRulesArray($rules);

        foreach ($rules as $field => $definition) {
            /** @var string|array<int, mixed> $definition */
            $parsedRules = $this->parser->parse($definition, (string) $field);

            $isNumeric = false;
            foreach ($parsedRules as $rule) {
                if ($rule instanceof IntegerTypeRule || $rule instanceof NumericRule) {
                    $isNumeric = true;
                    break;
                }
            }

            if ($isNumeric) {
                foreach ($parsedRules as $rule) {
                    if ($rule instanceof NumericAwareInterface) {
                        $rule->setNumeric(true);
                    }
                }
            }

            $builder->field((string) $field)->rules(...$parsedRules);
        }

        $schema = $builder->compile();
        $schema->recordCompileTime((hrtime(true) - $start) / 1e6);

        return $schema;
    }
}
