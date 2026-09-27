<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Support;

/**
 * Renders resources/compatibility-matrix.json as docs/rules.md.
 *
 * Regenerate with `php tests/generate_rule_matrix.php`; CompatibilityMatrixTest fails if the
 * committed docs/rules.md differs from what this produces.
 */
final class RuleMatrixDocument
{
    private const CATEGORY_TITLES = [
        'core_presence' => 'Core & presence',
        'conditional' => 'Conditionals',
        'acceptance' => 'Acceptance',
        'type' => 'Types',
        'string' => 'Strings',
        'numeric_size' => 'Numbers & size',
        'comparison' => 'Comparison',
        'date' => 'Dates',
        'array' => 'Arrays',
        'file' => 'Files',
        'database' => 'Database',
        'auth' => 'Auth',
        'other' => 'vi/validation extensions',
        'meta' => 'Internal',
    ];

    private const STATUS = [
        'full' => '✅ verified',
        'partial' => '🟡 partial',
        'divergent' => '🔴 divergent',
        'not_applicable' => '➖ n/a',
        'unsupported' => '⛔ unsupported',
    ];

    public static function path(): string
    {
        return dirname(__DIR__, 2) . '/docs/rules.md';
    }

    public static function matrixPath(): string
    {
        return dirname(__DIR__, 2) . '/resources/compatibility-matrix.json';
    }

    public static function render(): string
    {
        /** @var array<string, array<string, mixed>> $matrix */
        $matrix = json_decode((string) file_get_contents(self::matrixPath()), true, 512, JSON_THROW_ON_ERROR);
        $verified = $matrix['_verified_against'] ?? [];

        $out = [];
        $out[] = '# Laravel rule compatibility matrix';
        $out[] = '';
        $out[] = '<!-- Generated from resources/compatibility-matrix.json by `php tests/generate_rule_matrix.php`. Do not edit by hand. -->';
        $out[] = '';
        $out[] = 'Every rule vi/validation knows about, and every rule the supported Laravel versions define.';
        $out[] = sprintf(
            'Parity is verified against the real `illuminate/validation` on Laravel %s × PHP %s in CI.',
            implode(' / ', (array) ($verified['laravel'] ?? [])),
            implode(' / ', (array) ($verified['php'] ?? []))
        );
        $out[] = '';
        $out[] = '- **Engine**: the rule runs in `ValidatorEngine`, which covers every rule vi/validation supports.';
        $out[] = '- **Native**: the rule can be inlined by `NativeCompiler`, i.e. its class implements `NativeCompilableInterface`. A schema runs natively only if *every* rule in it is native. Engine support never implies native support.';
        $out[] = '- **Parity**: ✅ pass/fail and failed fields match Laravel (parity-tested); 🟡 matches except for the noted cases; 🔴 intentionally different (pinned by a divergence test); ➖ no Laravel counterpart.';
        $out[] = '- **Nested / wildcard**: ' . (string) ($matrix['_attribute_addressing']['notes'] ?? '');
        $out[] = '';

        $byCategory = [];
        foreach ($matrix as $name => $entry) {
            if (str_starts_with($name, '_') || str_starts_with($name, '$')) {
                continue;
            }
            $byCategory[(string) $entry['category']][$name] = $entry;
        }

        $counts = ['total' => 0, 'native' => 0, 'full' => 0];
        foreach (self::CATEGORY_TITLES as $category => $title) {
            if (!isset($byCategory[$category])) {
                continue;
            }
            ksort($byCategory[$category]);

            $out[] = '## ' . $title;
            $out[] = '';
            $out[] = '| Rule | Engine | Native | Parity | Laravel | Dependencies | Notes |';
            $out[] = '| :--- | :---: | :---: | :--- | :--- | :--- | :--- |';

            foreach ($byCategory[$category] as $name => $entry) {
                $counts['total']++;
                $counts['native'] += $entry['native_compilable'] ? 1 : 0;
                $counts['full'] += $entry['status'] === 'full' ? 1 : 0;

                $out[] = sprintf(
                    '| `%s` | %s | %s | %s | %s | %s | %s |',
                    $name,
                    $entry['engine'] ? 'yes' : 'no',
                    $entry['native_compilable'] ? 'yes' : 'no',
                    self::STATUS[$entry['status']] ?? $entry['status'],
                    $entry['laravel_since'] !== null ? $entry['laravel_since'] . '+' : 'all',
                    $entry['dependencies'] === [] ? '—' : implode(', ', $entry['dependencies']),
                    str_replace(['|', "\n"], ['\\|', ' '], (string) ($entry['notes'] ?? ''))
                );
            }
            $out[] = '';
        }

        array_splice($out, 5, 0, [sprintf(
            '**%d rules: %d with verified Laravel parity, %d natively compilable.**',
            $counts['total'],
            $counts['full'],
            $counts['native']
        ), '']);

        return implode("\n", $out);
    }
}
