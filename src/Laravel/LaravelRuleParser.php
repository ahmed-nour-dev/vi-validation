<?php

declare(strict_types=1);

namespace Vi\Validation\Laravel;

use Closure;
use Vi\Validation\Rules\ClosureRule;
use Vi\Validation\Rules\RuleInterface;
use Vi\Validation\Rules\RuleRegistry;

final class LaravelRuleParser
{
    private RuleRegistry $registry;

    public function __construct(?RuleRegistry $registry = null)
    {
        $this->registry = $registry ?? new RuleRegistry();
        if ($registry === null) {
            $this->registry->registerBuiltInRules();
        }
    }

    /**
     * @param string|array<int, string|Closure|RuleInterface> $definition
     * @return list<RuleInterface>
     */
    public function parse(string|array $definition, string $field = ''): array
    {
        $rules = [];

        $parts = is_array($definition) ? $definition : explode('|', $definition);

        foreach ($parts as $part) {
            // Handle closure rules
            if ($part instanceof Closure) {
                $rules[] = new ClosureRule($part);
                continue;
            }

            // Handle RuleInterface instances directly
            if ($part instanceof RuleInterface) {
                $rules[] = $part;
                continue;
            }

            if ($part === '') {
                continue;
            }

            [$name, $params] = $this->splitRule($part);

            $rule = $this->mapRule($name, $params, $field);

            if ($rule !== null) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function splitRule(string $rule): array
    {
        $segments = explode(':', $rule, 2);
        $name = $segments[0];

        if (!isset($segments[1])) {
            return [$name, []];
        }

        // regex/not_regex patterns routinely contain commas (e.g. the `{3,12}` quantifier in
        // '/^[A-Za-z0-9]{3,12}$/') that must not be split into separate parameters - unlike
        // every other rule, the whole remainder is exactly one parameter. Laravel's own
        // ValidationRuleParser::parseParameters() special-cases these same two rules for the
        // same reason; splitting on ',' here would silently mangle the pattern into garbage
        // that never matches, making the field fail validation unconditionally.
        if ($name === 'regex' || $name === 'not_regex') {
            return [$name, [$segments[1]]];
        }

        return [$name, explode(',', $segments[1])];
    }

    /**
     * @param list<string> $params
     */
    private function mapRule(string $name, array $params, string $field = ''): ?RuleInterface
    {
        $class = $this->registry->get($name);
        if ($class === null) {
            return null;
        }

        return match ($name) {
            // Conditional required rules
            'required_if', 'prohibited_if', 'prohibited_unless' => isset($params[0], $params[1]) ? new $class($params[0], array_slice($params, 1)) : null,
            'required_unless' => isset($params[0], $params[1]) ? new $class($params[0], array_slice($params, 1)) : null,

            'required_with', 'required_without', 'required_with_all', 'required_without_all', 'in', 'not_in', 'starts_with', 'ends_with', 'mimes' => !empty($params) || in_array($name, ['in', 'not_in', 'mimes'], true) ? new $class($params) : null,

            'required_array_keys', 'mimetypes' => !empty($params) ? new $class(...$params) : null,

            // Type rules with params
            'date' => new $class($params[0] ?? null),
            'date_format' => isset($params[0]) ? new $class($params[0]) : null,

            // String rules with single param
            'regex', 'not_regex' => isset($params[0]) ? new $class($params[0]) : null,
            'digits', 'max_file_size', 'min_file_size' => isset($params[0]) ? new $class((int) $params[0]) : null,
            'min', 'max', 'size', 'multiple_of' => isset($params[0]) ? new $class((float) $params[0]) : null,

            // Rules with multiple params
            'digits_between' => isset($params[0], $params[1]) ? new $class((int) $params[0], (int) $params[1]) : null,
            'between' => isset($params[0], $params[1]) ? new $class((float) $params[0], (float) $params[1]) : null,

            'doesnt_start_with', 'doesnt_end_with' => !empty($params) ? new $class(...$params) : null,

            'same', 'different', 'gt', 'gte', 'lt', 'lte', 'after', 'after_or_equal', 'before', 'before_or_equal', 'date_equals' => isset($params[0]) ? new $class($params[0]) : null,

            'distinct' => new $class(
                in_array('strict', $params, true),
                in_array('ignore_case', $params, true)
            ),

            // Database rules: full Laravel exists/unique parameter grammar
            'exists' => isset($params[0]) ? $this->buildExistsRule($class, $field, $params) : null,
            'unique' => isset($params[0]) ? $this->buildUniqueRule($class, $field, $params) : null,

            // Dependent-value conditional rules (single-value only; see LaravelRuleParser docs)
            'accepted_if', 'declined_if', 'exclude_if', 'exclude_unless', 'missing_if', 'missing_unless' =>
                isset($params[0]) ? new $class($params[0], $params[1] ?? null) : null,

            'exclude_with', 'exclude_without', 'required_if_accepted' => isset($params[0]) ? new $class($params[0]) : null,

            'decimal' => isset($params[0])
                ? new $class((int) $params[0], isset($params[1]) ? (int) $params[1] : null)
                : null,

            'enum' => isset($params[0]) ? new $class($params[0]) : null,

            'dimensions' => new $class($this->parseAssocParams($params)),

            'extensions', 'missing_with', 'missing_with_all', 'prohibits' => new $class(...$params),

            // Default: Simple instantiation for rules without params
            default => new $class(),
        };
    }

    /**
     * Build an ExistsRule from Laravel's `exists:table,column,extra1,value1,...` grammar.
     *
     * @param class-string<RuleInterface> $class
     * @param list<string> $params
     */
    private function buildExistsRule(string $class, string $field, array $params): RuleInterface
    {
        [$connection, $table] = $this->parseTable($params[0]);
        $column = $this->resolveColumn($params, $field);
        $extra = $this->parseExtraConditions(array_slice($params, 2));

        return new $class($table, $column, $extra, $connection);
    }

    /**
     * Build a UniqueRule from Laravel's `unique:table,column,ignore,idColumn,extra1,value1,...` grammar.
     *
     * Not supported: the `[field]` bracket syntax for a per-row dynamic ignore id, and referencing an
     * Eloquent model class as the table. Both require resolving values at validate-time rather than
     * compile-time and are out of scope for this parser.
     *
     * @param class-string<RuleInterface> $class
     * @param list<string> $params
     */
    private function buildUniqueRule(string $class, string $field, array $params): RuleInterface
    {
        [$connection, $table] = $this->parseTable($params[0]);
        $column = $this->resolveColumn($params, $field);

        $ignoreId = isset($params[2]) ? $this->prepareUniqueId($params[2]) : null;
        $idColumn = $params[3] ?? 'id';
        $extra = $this->parseExtraConditions(array_slice($params, 4));

        return new $class($table, $column, $ignoreId, $idColumn, $extra, $connection);
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function parseTable(string $table): array
    {
        if (str_contains($table, '.')) {
            [$connection, $table] = explode('.', $table, 2);
            return [$connection, $table];
        }

        return [null, $table];
    }

    /**
     * @param list<string> $params
     */
    private function resolveColumn(array $params, string $field): string
    {
        if (isset($params[1]) && $params[1] !== '' && $params[1] !== 'NULL') {
            return $params[1];
        }

        return $field !== '' ? $field : 'id';
    }

    private function prepareUniqueId(string $id): mixed
    {
        if (strtolower($id) === 'null') {
            return null;
        }

        if (filter_var($id, FILTER_VALIDATE_INT) !== false) {
            return (int) $id;
        }

        return $id;
    }

    /**
     * Parse alternating `column,value,column,value,...` segments into an assoc array, as used for
     * exists/unique extra where-constraints.
     *
     * @param list<string> $segments
     * @return array<string, mixed>
     */
    private function parseExtraConditions(array $segments): array
    {
        $extra = [];
        $count = count($segments);

        for ($i = 0; $i + 1 < $count; $i += 2) {
            $extra[$segments[$i]] = $segments[$i + 1];
        }

        return $extra;
    }

    /**
     * Parse `key=value,key2=value2` segments (dimensions rule) into an assoc array.
     *
     * @param list<string> $params
     * @return array<string, mixed>
     */
    private function parseAssocParams(array $params): array
    {
        $result = [];

        foreach ($params as $param) {
            if (str_contains($param, '=')) {
                [$key, $value] = explode('=', $param, 2);
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
