<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Benchmarks;

/**
 * The fixed set of benchmark scenarios, in increasing complexity. Every generated row mixes in
 * a deliberate share of invalid rows (see each generator) so the failure/error-collection path
 * is represented too, not just the all-valid happy path.
 *
 * "simple" and "medium" only use rules NativeCompiler can inline (see the "Native compilation
 * compatibility contract" in README.md), so the `native` variant applies to them. "complex" and
 * "etl" deliberately include rules that contract excludes (`in`, `regex`, `required_if`), so
 * NativeCompiler::canCompile() is false for them and the `native` variant is reported as
 * unsupported/falls back to `compiled` — an accurate reflection of the documented behavior,
 * not a benchmark bug.
 */
final class ScenarioRegistry
{
    /**
     * @return array<string, Scenario>
     */
    public static function all(): array
    {
        return [
            'simple' => self::simple(),
            'medium' => self::medium(),
            'complex' => self::complex(),
            'etl' => self::etl(),
        ];
    }

    public static function get(string $name): Scenario
    {
        $scenario = self::all()[$name] ?? null;
        if ($scenario === null) {
            throw new \InvalidArgumentException("Unknown benchmark scenario '$name'.");
        }

        return $scenario;
    }

    private static function simple(): Scenario
    {
        return new Scenario(
            name: 'simple',
            description: 'Three flat fields, only NativeCompiler-inlinable rules (required/string/email/integer/min).',
            rules: [
                'name' => 'required|string|min:3|max:50',
                'email' => 'required|email',
                // 'sometimes', not 'nullable': LaravelRuleParser turns a 'nullable' token into a
                // NullableRule instance that CompiledField keeps in getRules() as a marker (unlike
                // 'sometimes', which it fully consumes into the isSometimes() flag) - and
                // NativeCompiler has no inlined equivalent for that leftover marker, so a
                // pipe-string 'nullable' silently defeats native compilation for the whole field.
                // 'sometimes' (an omitted key, see the generator below) gets the same "field is
                // optional" coverage while staying native-compilable.
                'age' => 'sometimes|integer|min:18',
            ],
            rowGenerator: static function (int $i): array {
                $invalid = $i % 10 === 0;

                $row = [
                    'name' => $invalid ? 'Al' : ('User ' . $i),
                    'email' => $invalid ? 'not-an-email' : ('user' . $i . '@example.com'),
                ];

                if ($i % 4 !== 0) {
                    $row['age'] = 18 + ($i % 60);
                }

                return $row;
            },
        );
    }

    private static function medium(): Scenario
    {
        return new Scenario(
            name: 'medium',
            description: 'Adds a boolean field and a nested address.* group, still fully NativeCompiler-inlinable.',
            rules: [
                'name' => 'required|string|min:3|max:50',
                'email' => 'required|email',
                // See simple()'s comment on why 'sometimes' is used instead of 'nullable' here.
                'age' => 'sometimes|integer|min:18|max:120',
                'active' => 'required|boolean',
                'address.city' => 'required|string|max:100',
                'address.zip' => 'required|string|min:5|max:5',
            ],
            rowGenerator: static function (int $i): array {
                $invalid = $i % 10 === 0;

                $row = [
                    'name' => $invalid ? 'Al' : ('User ' . $i),
                    'email' => $invalid ? 'not-an-email' : ('user' . $i . '@example.com'),
                    'active' => $i % 2 === 0,
                    'address' => [
                        'city' => 'City ' . ($i % 500),
                        'zip' => $invalid ? 'ab' : str_pad((string) (10000 + ($i % 90000)), 5, '0', STR_PAD_LEFT),
                    ],
                ];

                if ($i % 4 !== 0) {
                    $row['age'] = 18 + ($i % 90);
                }

                return $row;
            },
        );
    }

    private static function complex(): Scenario
    {
        return new Scenario(
            name: 'complex',
            description: 'Adds in:, regex:, required_if: and a nullable url field — not natively compilable.',
            rules: [
                'name' => 'required|string|min:3|max:50',
                'email' => 'required|email',
                'age' => 'nullable|integer|min:18|max:120',
                'type' => 'required|in:individual,business',
                'company_name' => 'required_if:type,business|string|max:100',
                'address.city' => 'required|string|max:100',
                'address.zip' => 'required|regex:/^\d{5}$/',
                'website' => 'nullable|url',
            ],
            rowGenerator: static function (int $i): array {
                $invalid = $i % 10 === 0;
                $isBusiness = $i % 3 === 0;

                return [
                    'name' => $invalid ? 'Al' : ('User ' . $i),
                    'email' => $invalid ? 'not-an-email' : ('user' . $i . '@example.com'),
                    'age' => $i % 4 === 0 ? null : 18 + ($i % 90),
                    'type' => $isBusiness ? 'business' : 'individual',
                    'company_name' => $isBusiness ? ('Company ' . $i) : null,
                    'address' => [
                        'city' => 'City ' . ($i % 500),
                        'zip' => $invalid ? 'bad' : str_pad((string) (10000 + ($i % 90000)), 5, '0', STR_PAD_LEFT),
                    ],
                    'website' => $i % 5 === 0 ? ('https://example' . $i . '.test') : null,
                ];
            },
        );
    }

    private static function etl(): Scenario
    {
        return new Scenario(
            name: 'etl',
            description: 'Realistic bulk contact-import shape (12 fields, nested address, conditional '
                . 'newsletter preference, ~14% dirty rows) rather than a microbenchmark.',
            rules: [
                'external_id' => 'required|string|max:64',
                'first_name' => 'required|string|max:100',
                'last_name' => 'required|string|max:100',
                'email' => 'required|email',
                'phone' => 'nullable|regex:/^\+?[0-9\-\s]{7,20}$/',
                'company' => 'nullable|string|max:150',
                'source' => 'required|in:import,api,manual',
                'tags' => 'nullable|array',
                'address.line1' => 'required|string|max:200',
                'address.city' => 'required|string|max:100',
                'address.postal_code' => 'required|regex:/^[A-Za-z0-9\- ]{3,12}$/',
                'address.country' => 'required|string|size:2',
                'marketing_opt_in' => 'required|boolean',
                'newsletter_frequency' => 'required_if:marketing_opt_in,1|in:daily,weekly,monthly',
            ],
            rowGenerator: static function (int $i): array {
                $invalid = $i % 7 === 0;
                $optIn = $i % 2 === 0;

                return [
                    'external_id' => 'ext-' . $i,
                    'first_name' => $invalid ? '' : ('First' . $i),
                    'last_name' => 'Last' . $i,
                    'email' => $invalid ? ('invalid-email-' . $i) : ('contact' . $i . '@example.com'),
                    'phone' => $i % 3 === 0 ? null : ('+1-555-' . str_pad((string) ($i % 10000), 4, '0', STR_PAD_LEFT)),
                    'company' => $i % 4 === 0 ? ('Company ' . $i) : null,
                    'source' => ['import', 'api', 'manual'][$i % 3],
                    'tags' => $i % 5 === 0 ? ['vip'] : null,
                    'address' => [
                        'line1' => $invalid ? '' : ('123 Main St ' . $i),
                        'city' => 'City ' . ($i % 800),
                        'postal_code' => $invalid ? '#' : ('PC-' . ($i % 90000)),
                        'country' => 'US',
                    ],
                    'marketing_opt_in' => $optIn,
                    'newsletter_frequency' => $optIn ? (['daily', 'weekly', 'monthly'][$i % 3]) : null,
                ];
            },
        );
    }
}
