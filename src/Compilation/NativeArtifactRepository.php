<?php

declare(strict_types=1);

namespace Vi\Validation\Compilation;

use Closure;
use Vi\Validation\Execution\CompiledSchema;

/**
 * Filesystem store for generated native validator closures.
 *
 * Lifecycle guarantees:
 *
 * - **Keys are versioned.** Artifacts are stored under the schema's
 *   SchemaFingerprint::$artifactKey, which folds in the fingerprint format, compiler version
 *   and PHP_VERSION_ID. A compiler change or PHP upgrade therefore never loads an old file;
 *   prune() deletes those leftovers.
 * - **Writes are atomic and validated.** Generated code is syntax-checked (tokenized with
 *   TOKEN_PARSE, which parses without executing) before it touches the disk, written to a
 *   unique temporary file in the same directory and rename()d into place, so a reader sees
 *   either no file or a complete one - never a partial write.
 * - **Concurrent generation is serialized.** Writers take an exclusive flock() on a single
 *   per-directory lock file and re-check for a valid artifact after acquiring it, so N
 *   workers racing on a cold cache generate the artifact once.
 * - **Loads are verified.** Every artifact starts with a header recording its format, key,
 *   compiler/PHP versions and a SHA-256 of its body. load() rejects a file whose header or
 *   checksum doesn't match, that fails to parse, or that doesn't return a Closure producing
 *   the expected result shape. A rejected artifact is deleted and load() returns null, so the
 *   caller falls back to ValidatorEngine (and a later store() regenerates it).
 * - **Loads are memoized per process.** A verified closure is kept in a static map keyed by
 *   directory + key, so in long-running workers (Octane, queues) the file is read, hashed and
 *   required at most once per process, not once per request. Generated closures are pure
 *   functions of their input and safe to share. flushMemory() empties the map.
 */
final class NativeArtifactRepository
{
    /** Bump when the on-disk artifact layout (header, body contract) changes. */
    public const ARTIFACT_FORMAT = '1';

    private const HEADER_PREFIX = '// vi-validation-native ';

    /** @var array<string, Closure> directory|key => verified closure */
    private static array $loaded = [];

    private string $directory;

    /**
     * @param string|null $signingKey Optional secret (e.g. Laravel's APP_KEY). When set, every
     *        artifact's header carries an HMAC-SHA256 of its body instead of a plain SHA-256,
     *        so a file planted in the cache directory by anyone who doesn't know the secret is
     *        rejected before it is ever required.
     */
    public function __construct(
        string $directory,
        private readonly NativeCompiler $compiler = new NativeCompiler(),
        private readonly ?string $signingKey = null,
    ) {
        $this->directory = rtrim($directory, '/\\');
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function keyFor(CompiledSchema $schema): string
    {
        return $schema->fingerprint()->artifactKey;
    }

    /**
     * @throws \InvalidArgumentException if $key isn't a plain identifier: keys become file
     *         names, so anything that could traverse or escape the directory is rejected.
     */
    public function pathFor(string $key): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $key) !== 1) {
            throw new \InvalidArgumentException('Invalid native artifact key.');
        }

        return $this->directory . '/' . $key . '.php';
    }

    public function has(string $key): bool
    {
        return isset(self::$loaded[$this->memoKey($key)]) || is_file($this->pathFor($key));
    }

    /**
     * Generate and persist the artifact for a schema. Returns its path, or null when the
     * schema can't have one (unstable fingerprint or a rule NativeCompiler can't inline).
     *
     * Idempotent: if a valid artifact already exists nothing is regenerated.
     */
    public function store(CompiledSchema $schema): ?string
    {
        $fingerprint = $schema->fingerprint();
        if (!$fingerprint->stable || !$this->compiler->canCompile($schema)) {
            return null;
        }

        $key = $fingerprint->artifactKey;
        $path = $this->pathFor($key);

        if ($this->isValidArtifactFile($path, $key)) {
            return $path;
        }

        $this->ensureDirectory();

        $lockPath = $this->directory . '/.lock';
        $lock = is_link($lockPath) ? false : @fopen($lockPath, 'c');
        if ($lock !== false) {
            flock($lock, LOCK_EX);
        }

        try {
            // Another process may have produced it while we waited for the lock.
            if ($this->isValidArtifactFile($path, $key)) {
                return $path;
            }

            try {
                $body = $this->compiler->compile($schema);
            } catch (UnsupportedNativeRuleException) {
                return null;
            }

            $code = $this->wrap($key, $body);
            $this->assertParses($code);

            $tmp = $this->directory . '/.' . $key . '.' . bin2hex(random_bytes(8)) . '.tmp';
            // 'x' = exclusive create: fails instead of following a pre-planted file/symlink.
            $handle = @fopen($tmp, 'x');
            if ($handle === false) {
                throw new NativeArtifactException("Failed to create temporary native artifact {$tmp}.");
            }
            $written = fwrite($handle, $code);
            fclose($handle);
            if ($written !== strlen($code)) {
                @unlink($tmp);
                throw new NativeArtifactException("Failed to write native artifact to {$tmp}.");
            }
            @chmod($tmp, 0644);

            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                throw new NativeArtifactException("Failed to move native artifact into place at {$path}.");
            }

            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($path, true);
            }

            return $path;
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * Load and verify the artifact stored under $key.
     *
     * Returns null when there is no artifact or it is invalid; an invalid artifact is
     * deleted so it is regenerated on the next store().
     *
     * @return (Closure(array<string, mixed>): array{valid: bool, errors: array<string, list<array{rule: string, params: array<string, mixed>, message: string|null}>>, excluded_fields: list<string>})|null
     */
    public function load(string $key): ?Closure
    {
        $memoKey = $this->memoKey($key);
        if (isset(self::$loaded[$memoKey])) {
            /** @phpstan-ignore-next-line verified at first load */
            return self::$loaded[$memoKey];
        }

        if (preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $key) !== 1) {
            return null;
        }

        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return null;
        }

        if (!$this->isTrustworthyLocation($path)) {
            // Don't delete: we don't own a file we refuse to trust. Just never execute it.
            return null;
        }

        $closure = $this->verifyAndRequire($path, $key);
        if ($closure === null) {
            $this->discard($path);
            return null;
        }

        /** @phpstan-ignore-next-line verified above */
        return self::$loaded[$memoKey] = $closure;
    }

    /**
     * @return (Closure(array<string, mixed>): array{valid: bool, errors: array<string, list<array{rule: string, params: array<string, mixed>, message: string|null}>>, excluded_fields: list<string>})|null
     */
    public function loadFor(CompiledSchema $schema): ?Closure
    {
        if (!$schema->fingerprint()->stable) {
            return null;
        }

        return $this->load($schema->fingerprint()->artifactKey);
    }

    /**
     * Delete every artifact (and leftover temp file) in the directory. Returns the number of
     * artifacts removed.
     */
    public function clear(): int
    {
        return $this->deleteMatching(static fn (): bool => true);
    }

    /**
     * Delete artifacts that the current package/PHP runtime would never load: those written
     * by another artifact format, compiler version or PHP version, or that are corrupt.
     * Returns the number removed.
     */
    public function prune(): int
    {
        return $this->deleteMatching(function (string $path): bool {
            $header = $this->readHeader($path);

            return $header === null
                || ($header['format'] ?? null) !== self::ARTIFACT_FORMAT
                || ($header['compiler'] ?? null) !== NativeCompiler::COMPILER_VERSION
                || ($header['php'] ?? null) !== (string) PHP_VERSION_ID;
        });
    }

    /**
     * Forget every closure memoized in this process (all repositories).
     */
    public static function flushMemory(): void
    {
        self::$loaded = [];
    }

    private function memoKey(string $key): string
    {
        return $this->directory . '|' . $key;
    }

    private function wrap(string $key, string $body): string
    {
        if (!str_starts_with($body, "<?php\n")) {
            throw new NativeArtifactException('NativeCompiler output must start with "<?php\\n".');
        }

        $rest = substr($body, strlen("<?php\n"));
        $header = self::HEADER_PREFIX . http_build_query([
            'format' => self::ARTIFACT_FORMAT,
            'key' => $key,
            'compiler' => NativeCompiler::COMPILER_VERSION,
            'php' => PHP_VERSION_ID,
        ] + $this->signatureFields($rest));

        return "<?php\n" . $header . "\n" . $rest;
    }

    private function assertParses(string $code): void
    {
        try {
            // Parses (and throws ParseError) without executing anything.
            $tokens = token_get_all($code, TOKEN_PARSE);
            unset($tokens);
        } catch (\ParseError $e) {
            throw new NativeArtifactException(
                'NativeCompiler generated invalid PHP; refusing to persist it: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * @return array<string, string>|null
     */
    private function readHeader(string $path, ?string &$rest = null): ?array
    {
        $contents = @file_get_contents($path);
        if ($contents === false || !str_starts_with($contents, "<?php\n" . self::HEADER_PREFIX)) {
            return null;
        }

        $afterOpen = substr($contents, strlen("<?php\n"));
        $newline = strpos($afterOpen, "\n");
        if ($newline === false) {
            return null;
        }

        parse_str(substr($afterOpen, strlen(self::HEADER_PREFIX), $newline - strlen(self::HEADER_PREFIX)), $header);
        $rest = substr($afterOpen, $newline + 1);

        /** @var array<string, string> $header */
        return $header;
    }

    private function isValidArtifactFile(string $path, string $key): bool
    {
        if (!is_file($path) || is_link($path)) {
            return false;
        }

        $header = $this->readHeader($path, $rest);

        if ($header === null || $rest === null
            || ($header['format'] ?? null) !== self::ARTIFACT_FORMAT
            || ($header['key'] ?? null) !== $key
        ) {
            return false;
        }

        foreach ($this->signatureFields($rest) as $field => $expected) {
            if (!is_string($header[$field] ?? null) || !hash_equals($expected, $header[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    private function signatureFields(string $body): array
    {
        return $this->signingKey !== null
            ? ['hmac' => hash_hmac('sha256', $body, $this->signingKey)]
            : ['sha256' => hash('sha256', $body)];
    }

    /**
     * Refuse to execute a file that anyone else on the machine could have put there: a
     * symlink, a world-writable file, or a file in a world-writable directory without the
     * sticky bit. (POSIX permission checks are skipped on Windows.)
     */
    private function isTrustworthyLocation(string $path): bool
    {
        if (is_link($path)) {
            return false;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            return true;
        }

        $filePerms = @fileperms($path);
        $dirPerms = @fileperms($this->directory);
        if ($filePerms === false || $dirPerms === false) {
            return false;
        }

        $worldWritableFile = ($filePerms & 0002) !== 0;
        $worldWritableDir = ($dirPerms & 0002) !== 0 && ($dirPerms & 01000) === 0;

        return !$worldWritableFile && !$worldWritableDir;
    }

    private function verifyAndRequire(string $path, string $key): ?Closure
    {
        if (!$this->isValidArtifactFile($path, $key)) {
            return null;
        }

        try {
            $closure = (static fn (string $__path): mixed => require $__path)($path);
        } catch (\Throwable) {
            return null;
        }

        if (!$closure instanceof Closure) {
            return null;
        }

        // Shape check, once per process: generated closures are pure, so a probe call is safe.
        try {
            $probe = $closure([]);
        } catch (\Throwable) {
            return null;
        }

        if (
            !is_array($probe)
            || !is_bool($probe['valid'] ?? null)
            || !is_array($probe['errors'] ?? null)
            || !is_array($probe['excluded_fields'] ?? null)
        ) {
            return null;
        }

        return $closure;
    }

    private function discard(string $path): void
    {
        @unlink($path);

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        if (!@mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            throw new NativeArtifactException("Unable to create native artifact directory {$this->directory}.");
        }
    }

    /**
     * @param callable(string): bool $shouldDelete
     */
    private function deleteMatching(callable $shouldDelete): int
    {
        if (!is_dir($this->directory)) {
            return 0;
        }

        $removed = 0;

        foreach (glob($this->directory . '/*.php') ?: [] as $file) {
            if ($shouldDelete($file)) {
                $this->discard($file);
                $removed++;
            }
        }

        foreach (glob($this->directory . '/.*.tmp') ?: [] as $tmp) {
            @unlink($tmp);
        }

        self::flushMemory();

        return $removed;
    }
}
