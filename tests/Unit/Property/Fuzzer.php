<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Property;

/**
 * Tiny seeded generator for property-based tests (no external dependencies).
 *
 * Every random choice goes through mt_rand() seeded with one integer, so any failure is
 * reproducible from its seed:
 *
 *   VI_FUZZ_SEED=123456 ./vendor/bin/phpunit --filter PropertyTest
 *   VI_FUZZ_ITERATIONS=5000 ./vendor/bin/phpunit tests/Unit/Property   # longer run
 */
final class Fuzzer
{
    public readonly int $seed;

    public function __construct(?int $seed = null)
    {
        $env = getenv('VI_FUZZ_SEED');
        $this->seed = $seed ?? ($env !== false && $env !== '' ? (int) $env : random_int(1, PHP_INT_MAX >> 1));
        mt_srand($this->seed);
    }

    public static function iterations(int $default): int
    {
        $env = getenv('VI_FUZZ_ITERATIONS');

        return $env !== false && $env !== '' ? max(1, (int) $env) : $default;
    }

    public function reproduce(string $test): string
    {
        return "Reproduce with: VI_FUZZ_SEED={$this->seed} ./vendor/bin/phpunit --filter {$test}";
    }

    public function int(int $min, int $max): int
    {
        return mt_rand($min, $max);
    }

    public function bool(int $percentTrue = 50): bool
    {
        return mt_rand(1, 100) <= $percentTrue;
    }

    /**
     * @template T
     * @param list<T> $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    /**
     * Values chosen to stress PHP type juggling, emptiness, Unicode and numeric edge cases.
     */
    public function value(int $depth = 0): mixed
    {
        return match (mt_rand(0, 12)) {
            0 => null,
            1 => $this->bool(),
            2 => $this->pick([0, 1, -1, 17, 18, 255, PHP_INT_MAX, PHP_INT_MIN, mt_rand(-1000, 1000)]),
            3 => $this->pick([0.0, -0.0, 1.5, -2.25, 1e20, 1e-9, INF, -INF, NAN, 18.0]),
            4 => $this->pick(['', ' ', "\t", "\n", '0', '1', '-1', '00', '1.0', '1e3', ' 12', '12 ', '0x1A', '+5', '.5', '18', 'NaN', 'INF']),
            5 => $this->pick(['true', 'false', 'on', 'off', 'yes', 'no', 'null']),
            6 => $this->pick(['user@example.com', 'a@b', 'not-an-email', 'ü@exämple.de', '"quoted"@example.com', 'a@@b.com']),
            7 => $this->pick(['https://example.com/x?y=1', 'ftp://host', 'example.com', 'http://', 'javascript:alert(1)']),
            8 => $this->pick(['127.0.0.1', '::1', '256.1.1.1', '2001:db8::1', '1.2.3']),
            9 => $this->pick(['{"a":1}', '[]', '{', 'null', '"str"', '1']),
            10 => $this->unicode(),
            11 => $this->pick(['abc', 'ABC', 'abc123', 'a-b_c', 'a b', 'Ünïcödé', 'مرحبا', '日本語', "e\u{0301}", '🙂', str_repeat('x', 300)]),
            default => $depth < 2 ? $this->arrayValue($depth + 1) : [],
        };
    }

    public function unicode(): string
    {
        $chars = ['a', 'Z', '9', '_', '-', ' ', 'é', "e\u{0301}", 'ß', 'Ω', 'ж', 'ع', '中', '🙂', "\u{200B}", "\u{FEFF}", "\0"];
        $out = '';
        for ($i = 0, $n = mt_rand(0, 12); $i < $n; $i++) {
            $out .= $this->pick($chars);
        }

        return $out;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function arrayValue(int $depth = 0): array
    {
        $out = [];
        $list = $this->bool();
        for ($i = 0, $n = mt_rand(0, 4); $i < $n; $i++) {
            $key = $list ? $i : $this->pick(['a', 'b', 'c', 'x', '0', 'nested']);
            $out[$key] = $this->value($depth + 1);
        }

        return $out;
    }
}
