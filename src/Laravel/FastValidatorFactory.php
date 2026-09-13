<?php

declare(strict_types=1);

namespace Vi\Validation\Laravel;

use Vi\Validation\Cache\ArraySchemaCache;
use Vi\Validation\Cache\FileSchemaCache;
use Vi\Validation\Cache\SchemaCacheInterface;
use Vi\Validation\Execution\CompiledSchema;
use Vi\Validation\Execution\ValidatorEngine;
use Vi\Validation\Messages\MessageResolver;
use Vi\Validation\Messages\Translator;
use Vi\Validation\Schema\SchemaBuilder;
use Vi\Validation\SchemaValidator;

use Vi\Validation\Rules\RuleRegistry;
use Vi\Validation\Rules\IntegerTypeRule;
use Vi\Validation\Rules\NumericRule;
use Vi\Validation\Rules\NumericAwareInterface;

final class FastValidatorFactory
{
    /** @var array<string, mixed> */
    private array $config;

    private ?SchemaCacheInterface $cache = null;

    private RuleRegistry $registry;

    /**
     * @param array<string, mixed> $config
     * @param RuleRegistry|null $registry
     */
    public function __construct(array $config = [], ?RuleRegistry $registry = null)
    {
        $this->config = $config;
        $this->registry = $registry ?? new RuleRegistry();
        
        if ($registry === null) {
            $this->registry->registerBuiltInRules();
        }

        $this->initializeCache();
    }

    /**
     * Mirror Laravel's Validator::make signature at a high level.
     *
     * @param iterable<string, mixed> $data
     * @param array<string, mixed> $rules
     * @param array<string, string> $messages Custom error messages
     * @param array<string, string> $attributes Custom attribute names
     */
    public function make(
        iterable $data,
        array $rules,
        array $messages = [],
        array $attributes = []
    ): FastValidatorWrapper {
        $schemaValidator = $this->buildSchemaValidator($rules, $messages, $attributes);

        return new FastValidatorWrapper($schemaValidator, $data, $this->registry);
    }


    /**
     * Get or create schema cache instance.
     */
    public function getCache(): ?SchemaCacheInterface
    {
        return $this->cache;
    }

    /**
     * @param array<string, mixed> $rules
     * @param array<string, string> $messages
     * @param array<string, string> $attributes
     */
    private function buildSchemaValidator(
        array $rules,
        array $messages = [],
        array $attributes = []
    ): SchemaValidator {
        $cacheKey = $this->generateCacheKey($rules);
        
        // Try to get from cache
        if ($this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return $this->createValidatorWithSchema($cached, $messages, $attributes);
            }
        }

        // Build schema
        $parser = new LaravelRuleParser($this->registry);
        $builder = new SchemaBuilder();
        $builder->setRulesArray($rules);

        foreach ($rules as $field => $definition) {
            $fieldBuilder = $builder->field($field);
            $parsedRules = $parser->parse($definition, (string) $field);
            
            // Check for numeric context
            $isNumeric = false;
            foreach ($parsedRules as $rule) {
                $ruleClass = get_class($rule);
                if ($ruleClass === \Vi\Validation\Rules\IntegerTypeRule::class || $ruleClass === \Vi\Validation\Rules\NumericRule::class) {
                    $isNumeric = true;
                    break;
                }
            }
            
            // Apply numeric context to aware rules
            if ($isNumeric) {
                foreach ($parsedRules as $rule) {
                    if ($rule instanceof NumericAwareInterface) {
                        $rule->setNumeric(true);
                    }
                }
            }

            $fieldBuilder->rules(...$parsedRules);
        }

        $schema = $builder->compile();

        // Cache the schema
        if ($this->cache !== null) {
            $ttl = $this->config['cache']['ttl'] ?? 3600;
            $this->cache->put($cacheKey, $schema, $ttl);
        }

        return $this->createValidatorWithSchema($schema, $messages, $attributes);
    }

    /**
     * @param array<string, string> $messages
     * @param array<string, string> $attributes
     */
    private function createValidatorWithSchema(
        CompiledSchema $schema,
        array $messages = [],
        array $attributes = []
    ): SchemaValidator {
        // Create engine with custom messages/attributes
        $engine = $this->createEngine($messages, $attributes);

        return new SchemaValidator($schema, $engine);
    }

    /**
     * @param array<string, string> $messages
     * @param array<string, string> $attributes
     */
    private function createEngine(array $messages = [], array $attributes = []): ValidatorEngine
    {
        $locale = $this->config['localization']['locale'] ?? 'en';
        $fallbackLocale = $this->config['localization']['fallback_locale'] ?? 'en';

        $translator = new Translator($locale);
        $translator->setFallbackLocale($fallbackLocale);

        $messageResolver = new MessageResolver($translator);

        if (!empty($messages)) {
            $messageResolver->setCustomMessages($messages);
        }

        if (!empty($attributes)) {
            $messageResolver->setCustomAttributes($attributes);
        }

        $failFast = $this->config['performance']['fail_fast'] ?? false;
        $maxErrors = $this->config['performance']['max_errors'] ?? 100;

        return new ValidatorEngine($messageResolver, $failFast, $maxErrors);
    }

    private function initializeCache(): void
    {
        $cacheConfig = $this->config['cache'] ?? [];
        
        if (!($cacheConfig['enabled'] ?? true)) {
            return;
        }

        $driver = $cacheConfig['driver'] ?? 'array';

        if ($driver === 'file') {
            $path = $cacheConfig['path'] ?? sys_get_temp_dir() . '/vi-validation';
            $ttl = $cacheConfig['ttl'] ?? 3600;
            $this->cache = new FileSchemaCache($path, $ttl);
        } else {
            $this->cache = new ArraySchemaCache();
        }
    }

    /**
     * @param array<string, mixed> $rules
     */
    private function generateCacheKey(array $rules): string
    {
        return md5($this->serializeRules($rules));
    }

    /**
     * Serialize rules for cache key generation, handling closures and objects.
     */
    private function serializeRules(mixed $value): string
    {
        if ($value instanceof \Closure) {
            // Closures get a unique ID - effectively disables caching for rules with closures
            return 'closure:' . spl_object_id($value);
        }

        if (is_object($value)) {
            return get_class($value) . ':' . spl_object_id($value);
        }

        if (is_array($value)) {
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = $key . ':' . $this->serializeRules($item);
            }
            return '[' . implode(',', $parts) . ']';
        }

        return (string) $value;
    }
}
