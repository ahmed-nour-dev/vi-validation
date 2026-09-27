<?php

declare(strict_types=1);

namespace Vi\Validation\Compilation;

use Vi\Validation\Execution\CompiledSchema;
use Vi\Validation\Cache\SchemaCacheInterface;

final class ValidatorCompiler
{
    private ?SchemaCacheInterface $cache;
    private bool $precompile;
    private ?string $cachePath;
    private NativeCompiler $nativeCompiler;
    private ?NativeArtifactRepository $nativeRepository = null;

    /**
     * @param string|null $signingKey Optional secret (e.g. Laravel's APP_KEY) used to sign
     *        native artifacts and precompiled schema files, so files planted in $cachePath by
     *        anyone without the secret are never required/unserialized. See
     *        docs/native-compilation.md#security-model--trust-boundaries.
     */
    public function __construct(
        ?SchemaCacheInterface $cache = null,
        bool $precompile = false,
        ?string $cachePath = null,
        private readonly ?string $signingKey = null
    ) {
        $this->cache = $cache;
        $this->precompile = $precompile;
        $this->cachePath = $cachePath;
        $this->nativeCompiler = new NativeCompiler();
    }

    /**
     * Compile and cache a schema.
     *
     * @param array<string, mixed> $rules
     */
    public function compile(string $key, array $rules, callable $compiler): CompiledSchema
    {
        // Check object cache
        if ($this->cache !== null) {
            $cached = $this->cache->get($key);
            if ($cached !== null) {
                return $cached;
            }
        }

        // Compile the schema object
        $schema = $compiler($rules);

        // Store in object cache
        if ($this->cache !== null) {
            $this->cache->put($key, $schema);
        }

        // Native Compilation Path - keyed by the schema's canonical fingerprint, never by the
        // user's input format, so equivalent schemas share one artifact.
        if ($this->cachePath !== null) {
            $this->writeNativeFor($schema);
        }

        // Legacy precompile to file if enabled
        if ($this->precompile && $this->cachePath !== null) {
            $this->writePrecompiled($key, $schema);
        }

        return $schema;
    }

    /**
     * Whether a missing native artifact should be generated on first use (the
     * `compilation.precompile` option). When false, existing artifacts - e.g. generated at
     * deploy time - are still loaded, but none are generated at runtime.
     */
    public function isPrecompileEnabled(): bool
    {
        return $this->precompile && $this->cachePath !== null;
    }

    /**
     * The key a schema's native artifact is stored under: its fingerprint's artifactKey,
     * which already folds in the compiler version and PHP_VERSION_ID.
     */
    public function nativeKeyFor(CompiledSchema $schema): string
    {
        return $schema->fingerprint()->artifactKey;
    }

    /**
     * The native artifact store, or null when no cache path is configured.
     */
    public function nativeRepository(): ?NativeArtifactRepository
    {
        if ($this->cachePath === null) {
            return null;
        }

        return $this->nativeRepository ??= new NativeArtifactRepository(
            $this->cachePath . '/native',
            $this->nativeCompiler,
            $this->signingKey
        );
    }

    /**
     * Generate and persist the native artifact for a schema, keyed by its fingerprint.
     *
     * Returns the artifact path, or null when nothing was (or could be) written: no cache
     * path configured, the schema isn't fully native-compilable, its fingerprint is
     * unstable (it contains closures etc.) so a persisted artifact could be picked up by an
     * unrelated schema, or the artifact couldn't be written safely. None of these are
     * errors: validation keeps using ValidatorEngine.
     */
    public function writeNativeFor(CompiledSchema $schema): ?string
    {
        $repository = $this->nativeRepository();
        if ($repository === null) {
            return null;
        }

        try {
            return $repository->store($schema);
        } catch (NativeArtifactException) {
            return null;
        }
    }

    /**
     * Load the verified native closure for a schema, if an artifact exists. Corrupt or
     * mismatched artifacts are discarded and reported as absent.
     *
     * @return (\Closure(array<string, mixed>): array{valid: bool, errors: array<string, list<array{rule: string, params: array<string, mixed>, message: string|null}>>, excluded_fields: list<string>})|null
     */
    public function loadNativeFor(CompiledSchema $schema): ?\Closure
    {
        return $this->nativeRepository()?->loadFor($schema);
    }

    /**
     * Write optimized native PHP code to file under an explicit key.
     *
     * @deprecated Use writeNativeFor(), which derives the key from the schema's fingerprint.
     *             An artifact written under any other key is never loaded by SchemaValidator.
     */
    public function writeNative(string $key, CompiledSchema $schema): void
    {
        if ($key === $this->nativeKeyFor($schema)) {
            $this->writeNativeFor($schema);
        }
    }

    public function getNativePath(string $key): string
    {
        return $this->cachePath . '/native/' . $key . '.php';
    }

    /**
     * Load a precompiled schema.
     */
    public function loadPrecompiled(string $key): ?CompiledSchema
    {
        if ($this->cachePath === null) {
            return null;
        }

        $path = $this->getPrecompiledPath($key);

        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $payload = \Vi\Validation\Cache\PayloadSigner::unwrap($content, $this->signingKey);
        if ($payload === null) {
            return null;
        }

        $schema = @unserialize($payload);

        return $schema instanceof CompiledSchema ? $schema : null;
    }

    /**
     * Write a precompiled schema to file.
     */
    private function writePrecompiled(string $key, CompiledSchema $schema): void
    {
        if ($this->cachePath === null) {
            return;
        }

        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }

        $path = $this->getPrecompiledPath($key);
        
        \Vi\Validation\Cache\PayloadSigner::writeAtomically(
            $path,
            \Vi\Validation\Cache\PayloadSigner::wrap(serialize($schema), $this->signingKey)
        );
    }

    private function getPrecompiledPath(string $key): string
    {
        return $this->cachePath . '/' . md5($key) . '.compiled';
    }

    /**
     * Clear all precompiled schemas and native artifacts.
     */
    public function clearPrecompiled(): void
    {
        if ($this->cachePath === null || !is_dir($this->cachePath)) {
            return;
        }

        $files = glob($this->cachePath . '/*.compiled');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }

        $this->clearNative();
    }

    /**
     * Delete every native artifact. Returns the number removed.
     */
    public function clearNative(): int
    {
        return $this->nativeRepository()?->clear() ?? 0;
    }

    /**
     * Delete native artifacts the current compiler/PHP version would never load (and any
     * corrupt ones). Returns the number removed. Safe to run on every deploy.
     */
    public function pruneNative(): int
    {
        return $this->nativeRepository()?->prune() ?? 0;
    }

    /**
     * Generate a cache key from rules.
     *
     * @param array<string, mixed> $rules
     */
    public static function generateKey(array $rules): string
    {
        return md5(serialize($rules));
    }
}
