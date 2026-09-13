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

    public function __construct(
        ?SchemaCacheInterface $cache = null,
        bool $precompile = false,
        ?string $cachePath = null
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
        // For native compilation, we use a different key based on content + environment
        $nativeKey = NativeCompiler::generateKey($rules);
        
        // Check native cache first (this is the fastest path)
        if ($this->cachePath !== null) {
            $nativePath = $this->getNativePath($nativeKey);
            if (file_exists($nativePath)) {
                // We still need the schema object if we are not in a full-native environment
                // but for now let's focus on the generation
            }
        }

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

        // Native Compilation Path
        if ($this->cachePath !== null) {
            $this->writeNative($nativeKey, $schema);
        }

        // Legacy precompile to file if enabled
        if ($this->precompile && $this->cachePath !== null) {
            $this->writePrecompiled($key, $schema);
        }

        return $schema;
    }

    /**
     * Write optimized native PHP code to file.
     *
     * If the schema contains a rule that NativeCompiler cannot inline, no
     * file is written. SchemaValidator::validate() then finds no native
     * artifact for this key and falls back to ValidatorEngine, so a schema
     * can never be partially/incorrectly natively compiled.
     */
    public function writeNative(string $key, CompiledSchema $schema): void
    {
        if ($this->cachePath === null) {
            return;
        }

        $dir = $this->cachePath . '/native';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir . '/' . $key . '.php';

        // Only write if it doesn't exist (content-hash based)
        if (file_exists($path)) {
            return;
        }

        try {
            $code = $this->nativeCompiler->compile($schema);
        } catch (\Vi\Validation\Compilation\UnsupportedNativeRuleException) {
            // Refuse native compilation for this schema; ValidatorEngine
            // remains the deterministic fallback for correctness.
            return;
        }

        // Atomic write: temp file + rename
        $tmp = $path . '.' . uniqid('', true) . '.tmp';
        file_put_contents($tmp, $code, LOCK_EX);
        rename($tmp, $path);
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

        $schema = unserialize($content);

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
        
        // Atomic write for legacy too
        $tmp = $path . '.' . uniqid('', true) . '.tmp';
        file_put_contents($tmp, serialize($schema), LOCK_EX);
        rename($tmp, $path);
    }

    private function getPrecompiledPath(string $key): string
    {
        return $this->cachePath . '/' . md5($key) . '.compiled';
    }

    /**
     * Clear all precompiled schemas.
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

        $nativeFiles = glob($this->cachePath . '/native/*.php');
        if ($nativeFiles !== false) {
            foreach ($nativeFiles as $file) {
                unlink($file);
            }
        }
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
