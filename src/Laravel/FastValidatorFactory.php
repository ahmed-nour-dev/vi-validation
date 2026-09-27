<?php

declare(strict_types=1);

namespace Vi\Validation\Laravel;

use Vi\Validation\Cache\ArraySchemaCache;
use Vi\Validation\Cache\FileSchemaCache;
use Vi\Validation\Cache\SchemaCacheInterface;
use Vi\Validation\Compilation\ValidatorCompiler;
use Vi\Validation\Execution\CompiledSchema;
use Vi\Validation\Execution\ErrorMode;
use Vi\Validation\Execution\ValidatorEngine;
use Vi\Validation\Messages\MessageResolver;
use Vi\Validation\Messages\Translator;
use Vi\Validation\SchemaValidator;

use Vi\Validation\Rules\RuleRegistry;

final class FastValidatorFactory
{
    /** @var array<string, mixed> */
    private array $config;

    private ?SchemaCacheInterface $cache = null;

    private RuleRegistry $registry;

    private RuleSetCompiler $ruleSetCompiler;

    private ?ValidatorCompiler $compiler = null;

    /** @var array{hits: int, misses: int, uncacheable: int} */
    private array $cacheStats = ['hits' => 0, 'misses' => 0, 'uncacheable' => 0];

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

        $this->ruleSetCompiler = new RuleSetCompiler($this->registry);

        $this->initializeCache();
        $this->initializeCompiler();
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
     * Build (or fetch from the schema cache) the compiled schema for a rules array.
     *
     * @param array<string, mixed> $rules
     */
    public function compile(array $rules): CompiledSchema
    {
        $cacheKey = $this->generateCacheKey($rules);

        if ($this->cache !== null && $cacheKey !== null) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                $this->cacheStats['hits']++;
                return $cached;
            }
            $this->cacheStats['misses']++;
        } elseif ($this->cache !== null) {
            $this->cacheStats['uncacheable']++;
        }

        $schema = $this->ruleSetCompiler->compile($rules);

        if ($this->cache !== null && $cacheKey !== null) {
            $ttl = $this->config['cache']['ttl'] ?? 3600;
            $this->cache->put($cacheKey, $schema, $ttl);
        }

        return $schema;
    }

    /**
     * Ahead-of-time: generate and persist the native artifact for a rules array (e.g. from a
     * deploy script or a service provider's boot()), so no request ever pays for code
     * generation. Works whether or not `compilation.precompile` is enabled; requires
     * `compilation.cache_path`.
     *
     * Returns the artifact path, or null if the rules can't be natively compiled (the engine
     * is used for them) or no cache path is configured.
     *
     * @param array<string, mixed> $rules
     */
    public function precompile(array $rules): ?string
    {
        return $this->compiler?->writeNativeFor($this->compile($rules));
    }

    /**
     * Schema cache counters for this factory: hits, misses, and rule sets that can't be cached
     * (closures / non-serializable rule objects).
     *
     * @return array{hits: int, misses: int, uncacheable: int}
     */
    public function cacheStats(): array
    {
        return $this->cacheStats;
    }

    /**
     * Describe how a rules array will execute (see SchemaValidator::diagnostics()).
     *
     * @param array<string, mixed> $rules
     */
    public function diagnose(array $rules): \Vi\Validation\Diagnostics\SchemaDiagnostics
    {
        return \Vi\Validation\Diagnostics\SchemaInspector::inspect($this->compile($rules), $this->compiler);
    }

    /**
     * The native compiler/artifact store configured from `compilation.*`, if any.
     */
    public function getCompiler(): ?ValidatorCompiler
    {
        return $this->compiler;
    }

    /**
     * Delete every generated native artifact. Returns the number removed.
     */
    public function clearCompiled(): int
    {
        return $this->compiler?->clearNative() ?? 0;
    }

    /**
     * Delete native artifacts the current compiler/PHP version would never load. Returns the
     * number removed. Safe to run on every deploy.
     */
    public function pruneCompiled(): int
    {
        return $this->compiler?->pruneNative() ?? 0;
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
        return $this->createValidatorWithSchema($this->compile($rules), $messages, $attributes);
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
        $messageResolver = $this->createMessageResolver($messages, $attributes);

        $failFast = (bool) ($this->config['performance']['fail_fast'] ?? false);
        $maxErrors = (int) ($this->config['performance']['max_errors'] ?? 100);

        // The same resolver goes to the engine *and* the SchemaValidator (native path), so
        // custom messages/attributes apply whichever path executes.
        $errorMode = $this->config['performance']['error_mode'] ?? ErrorMode::All;
        if (!$errorMode instanceof ErrorMode) {
            $errorMode = ErrorMode::from((string) $errorMode);
        }

        return new SchemaValidator(
            $schema,
            new ValidatorEngine($messageResolver, $failFast, $maxErrors, $errorMode),
            $this->compiler,
            $messageResolver
        );
    }

    /**
     * @param array<string, string> $messages
     * @param array<string, string> $attributes
     */
    private function createMessageResolver(array $messages = [], array $attributes = []): MessageResolver
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

        return $messageResolver;
    }

    private function initializeCompiler(): void
    {
        $compilation = $this->config['compilation'] ?? [];
        $cachePath = $compilation['cache_path'] ?? null;

        if (!is_string($cachePath) || $cachePath === '') {
            return;
        }

        $signingKey = $this->config['security']['signing_key'] ?? null;

        $this->compiler = new ValidatorCompiler(
            null,
            (bool) ($compilation['precompile'] ?? false),
            $cachePath,
            is_string($signingKey) && $signingKey !== '' ? $signingKey : null
        );
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
            $signingKey = $this->config['security']['signing_key'] ?? null;
            $this->cache = new FileSchemaCache($path, $ttl, is_string($signingKey) && $signingKey !== '' ? $signingKey : null);
        } else {
            $this->cache = new ArraySchemaCache();
        }
    }

    /**
     * Content-based cache key for a rules array, or null when the rules can't be identified
     * by content and therefore must not be cached at all.
     *
     * Closures and non-serializable rule objects used to be keyed by spl_object_id(), but
     * PHP reuses object ids as soon as an object is freed: a later request passing a
     * *different* closure/rule object could then hit the earlier request's cached schema
     * (in the array cache) or another process's (in the file cache) and validate with the
     * wrong rules. Such rule sets are now rebuilt every time instead.
     *
     * @param array<string, mixed> $rules
     */
    private function generateCacheKey(array $rules): ?string
    {
        $serialized = $this->serializeRules($rules);

        return $serialized === null ? null : hash('sha256', $serialized);
    }

    /**
     * Serialize rules for cache key generation; null if any part has no stable identity.
     */
    private function serializeRules(mixed $value): ?string
    {
        if ($value instanceof \Closure) {
            return null;
        }

        if (is_object($value)) {
            try {
                return 'o:' . get_class($value) . ':' . serialize($value);
            } catch (\Throwable) {
                return null;
            }
        }

        if (is_array($value)) {
            $parts = [];
            foreach ($value as $key => $item) {
                $part = $this->serializeRules($item);
                if ($part === null) {
                    return null;
                }
                $parts[] = (is_int($key) ? 'i' : 's') . strlen((string) $key) . ':' . $key . '=' . $part;
            }
            return '[' . implode(',', $parts) . ']';
        }

        return get_debug_type($value) . ':' . var_export($value, true);
    }
}
