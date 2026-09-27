<?php

declare(strict_types=1);

namespace Vi\Validation\Cache;

/**
 * Integrity envelope + atomic writes for serialized cache files.
 *
 * Format: "vi1:<algo>:<hex digest>\n<payload>", where algo is "hmac" (HMAC-SHA256 with the
 * configured key) or "sha256" (plain checksum, detects corruption only). unwrap() returns
 * null for anything that doesn't verify, so callers never unserialize() unverified bytes.
 *
 * @internal
 */
final class PayloadSigner
{
    private const MAGIC = 'vi1';

    public static function wrap(string $payload, ?string $key): string
    {
        [$algo, $digest] = self::digest($payload, $key);

        return self::MAGIC . ':' . $algo . ':' . $digest . "\n" . $payload;
    }

    public static function unwrap(string $contents, ?string $key): ?string
    {
        $newline = strpos($contents, "\n");
        if ($newline === false) {
            return null;
        }

        $parts = explode(':', substr($contents, 0, $newline));
        if (count($parts) !== 3 || $parts[0] !== self::MAGIC) {
            return null;
        }

        $payload = substr($contents, $newline + 1);
        [$algo, $digest] = self::digest($payload, $key);

        // A keyed reader never accepts an unkeyed (or differently keyed) file.
        if ($parts[1] !== $algo || !hash_equals($digest, $parts[2])) {
            return null;
        }

        return $payload;
    }

    /**
     * Write via a unique temp file + rename so readers never observe a partial file.
     */
    public static function writeAtomically(string $path, string $contents): bool
    {
        $tmp = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(8)) . '.tmp';

        $handle = @fopen($tmp, 'x');
        if ($handle === false) {
            return false;
        }

        $written = fwrite($handle, $contents);
        fclose($handle);

        if ($written !== strlen($contents) || !@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function digest(string $payload, ?string $key): array
    {
        return $key !== null
            ? ['hmac', hash_hmac('sha256', $payload, $key)]
            : ['sha256', hash('sha256', $payload)];
    }
}
