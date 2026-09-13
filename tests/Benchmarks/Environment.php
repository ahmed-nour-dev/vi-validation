<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Benchmarks;

/**
 * Captures the environment facts a performance number is meaningless without: PHP/Laravel
 * version, OS, CPU, memory, OPcache/JIT configuration, and the exact commit the numbers were
 * produced from. Every field is best-effort — a field this process can't determine (e.g. CPU
 * model on a non-Linux host) is reported as "unknown" rather than throwing, since environment
 * capture must never be the reason a benchmark run fails.
 */
final class Environment
{
    /**
     * @return array<string, mixed>
     */
    public static function capture(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'os' => php_uname(),
            'cpu' => self::cpuModel(),
            'cpu_cores' => self::cpuCores(),
            'memory_limit' => ini_get('memory_limit') ?: 'unknown',
            'opcache_enabled_cli' => self::iniBool('opcache.enable_cli'),
            'opcache_jit' => ini_get('opcache.jit') ?: 'off',
            'opcache_jit_buffer_size' => ini_get('opcache.jit_buffer_size') ?: '0',
            'illuminate_validation_version' => self::installedVersion('illuminate/validation'),
            'git_commit' => self::gitCommit(),
        ];
    }

    private static function iniBool(string $key): bool
    {
        $value = ini_get($key);

        return $value !== false && $value !== '' && $value !== '0';
    }

    private static function cpuModel(): string
    {
        if (is_readable('/proc/cpuinfo')) {
            $contents = @file_get_contents('/proc/cpuinfo');
            if ($contents !== false && preg_match('/model name\s*:\s*(.+)/', $contents, $matches) === 1) {
                return trim($matches[1]);
            }
        }

        return 'unknown';
    }

    private static function cpuCores(): int|string
    {
        if (is_readable('/proc/cpuinfo')) {
            $contents = @file_get_contents('/proc/cpuinfo');
            if ($contents !== false) {
                $count = substr_count($contents, 'processor');
                if ($count > 0) {
                    return $count;
                }
            }
        }

        return 'unknown';
    }

    private static function installedVersion(string $package): string
    {
        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                return \Composer\InstalledVersions::getPrettyVersion($package) ?? 'unknown';
            } catch (\OutOfBoundsException) {
                return 'unknown';
            }
        }

        return 'unknown';
    }

    private static function gitCommit(): string
    {
        $output = @shell_exec('git rev-parse --short HEAD 2>/dev/null');

        return $output !== null ? trim($output) : 'unknown';
    }
}
