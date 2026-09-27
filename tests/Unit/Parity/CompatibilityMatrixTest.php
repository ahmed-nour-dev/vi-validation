<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Parity;

use PHPUnit\Framework\TestCase;
use Vi\Validation\Rules\RuleId;

/**
 * Keeps resources/compatibility-matrix.json honest against both the rule registry and the
 * actual parity test suite, so the matrix can't silently go stale:
 *
 * 1. Every RuleId enum case must have a matrix entry.
 * 2. Every entry claimed "full" or "partial" must be exercised by name in at least one
 *    *ParityTest.php file (a static text scan, not runtime coverage - see the class docblock
 *    below for why runtime accumulation across test classes would be execution-order-fragile).
 * 3. Every entry marked "divergent" must carry a non-empty explanation and be exercised via
 *    assertDivergence() somewhere in the suite.
 */
class CompatibilityMatrixTest extends TestCase
{
    private const VALID_STATUSES = ['full', 'partial', 'divergent', 'not_applicable', 'unsupported'];

    /** Validator::validateX() methods that aren't rules. */
    private const LARAVEL_NON_RULES = ['attribute', 'using_custom_rule', 'with_bag'];

    /** @var array<string, array{category: string, status: string, native_compilable: bool, notes: ?string}>|null */
    private static ?array $matrix = null;

    private static ?string $combinedTestSource = null;

    /**
     * @return array<string, array{category: string, status: string, native_compilable: bool, notes: ?string}>
     */
    private function matrix(): array
    {
        if (self::$matrix === null) {
            $path = __DIR__ . '/../../../resources/compatibility-matrix.json';
            $raw = file_get_contents($path);
            self::assertIsString($raw, "Could not read $path");

            $decoded = json_decode($raw, true);
            self::assertIsArray($decoded, 'compatibility-matrix.json is not valid JSON.');

            $matrix = [];
            foreach ($decoded as $key => $entry) {
                if (is_string($key) && str_starts_with($key, '_')) {
                    continue; // reserved metadata keys, e.g. "_attribute_addressing"
                }
                if ($key === '$schema_notes') {
                    continue;
                }

                self::assertIsArray($entry, "Matrix entry '$key' must be an object.");
                self::assertArrayHasKey('status', $entry, "Matrix entry '$key' is missing 'status'.");
                self::assertContains(
                    $entry['status'],
                    self::VALID_STATUSES,
                    "Matrix entry '$key' has an invalid status '{$entry['status']}'."
                );

                $matrix[$key] = $entry;
            }

            self::$matrix = $matrix;
        }

        return self::$matrix;
    }

    private function combinedTestSource(): string
    {
        if (self::$combinedTestSource === null) {
            $chunks = [];
            foreach (glob(__DIR__ . '/*ParityTest.php') ?: [] as $file) {
                if (basename($file) === 'CompatibilityMatrixTest.php') {
                    continue;
                }
                $contents = file_get_contents($file);
                if ($contents !== false) {
                    $chunks[] = $contents;
                }
            }

            self::$combinedTestSource = implode("\n", $chunks);
        }

        return self::$combinedTestSource;
    }

    /**
     * A rule name appears "as a rule" when it's bounded by non-identifier characters, so
     * e.g. 'max' matching inside 'max_file_size' or 'date' matching inside 'date_format'
     * doesn't count as covering the shorter rule.
     */
    private function sourceMentionsRule(string $ruleName): bool
    {
        $pattern = '/(?<![A-Za-z0-9_])' . preg_quote($ruleName, '/') . '(?![A-Za-z0-9_])/';

        return preg_match($pattern, $this->combinedTestSource()) === 1;
    }

    public function testEveryRuleIdHasAMatrixEntry(): void
    {
        $matrix = $this->matrix();
        $missing = [];

        foreach (RuleId::cases() as $ruleId) {
            if (!array_key_exists($ruleId->value, $matrix)) {
                $missing[] = $ruleId->value;
            }
        }

        self::assertSame(
            [],
            $missing,
            'RuleId cases missing from resources/compatibility-matrix.json: ' . implode(', ', $missing)
        );
    }

    public function testEveryFullOrPartialRuleHasAParityTest(): void
    {
        $uncovered = [];

        foreach ($this->matrix() as $ruleName => $entry) {
            if (!in_array($entry['status'], ['full', 'partial'], true)) {
                continue;
            }

            if (!$this->sourceMentionsRule($ruleName)) {
                $uncovered[] = $ruleName;
            }
        }

        self::assertSame(
            [],
            $uncovered,
            "Rules marked 'full'/'partial' in the compatibility matrix but not referenced in any "
                . 'tests/Unit/Parity/*ParityTest.php file: ' . implode(', ', $uncovered)
        );
    }

    public function testEveryDivergentRuleHasAnExplanationAndADivergenceTest(): void
    {
        $problems = [];

        foreach ($this->matrix() as $ruleName => $entry) {
            if ($entry['status'] !== 'divergent') {
                continue;
            }

            if (empty($entry['notes'])) {
                $problems[] = "$ruleName: missing an explanatory 'notes' string";
                continue;
            }

            if (!$this->sourceMentionsRule($ruleName) || !str_contains($this->combinedTestSource(), 'assertDivergence(')) {
                $problems[] = "$ruleName: not exercised via assertDivergence() in any parity test file";
            }
        }

        self::assertSame([], $problems, implode('; ', $problems));
    }

    public function testNotApplicableRulesHaveAnExplanation(): void
    {
        $problems = [];

        foreach ($this->matrix() as $ruleName => $entry) {
            if ($entry['status'] === 'not_applicable' && empty($entry['notes'])) {
                $problems[] = $ruleName;
            }
        }

        self::assertSame(
            [],
            $problems,
            "Rules marked 'not_applicable' but missing an explanatory 'notes' string: " . implode(', ', $problems)
        );
    }

    private function registry(): \Vi\Validation\Rules\RuleRegistry
    {
        $registry = new \Vi\Validation\Rules\RuleRegistry();
        $registry->registerBuiltInRules();

        return $registry;
    }

    public function testEngineColumnMatchesTheRuleRegistry(): void
    {
        $registry = $this->registry();
        $problems = [];

        foreach ($this->matrix() as $ruleName => $entry) {
            $resolvable = $registry->get($ruleName) !== null;
            if (($entry['engine'] ?? null) !== $resolvable) {
                $problems[] = "{$ruleName}: engine=" . var_export($entry['engine'] ?? null, true) . ' but registry ' . ($resolvable ? 'has' : 'lacks') . ' it';
            }
            if (($entry['status'] === 'unsupported') === $resolvable) {
                $problems[] = "{$ruleName}: status '{$entry['status']}' contradicts engine support";
            }
        }

        self::assertSame([], $problems, implode('; ', $problems));
    }

    /**
     * Native support must never be implied by engine support: the column has to equal whether
     * the rule's class actually implements NativeCompilableInterface.
     */
    public function testNativeColumnMatchesNativeCompilableInterface(): void
    {
        $registry = $this->registry();
        $problems = [];

        foreach ($this->matrix() as $ruleName => $entry) {
            $class = $registry->get($ruleName);
            $native = $class !== null && is_subclass_of($class, \Vi\Validation\Rules\NativeCompilableInterface::class);

            if (($entry['native_compilable'] ?? null) !== $native) {
                $problems[] = "{$ruleName}: matrix says native_compilable=" . var_export($entry['native_compilable'] ?? null, true)
                    . ', class ' . ($native ? 'implements' : 'does not implement') . ' NativeCompilableInterface';
            }
        }

        self::assertSame([], $problems, implode('; ', $problems));
    }

    /**
     * Every rule the installed Laravel validator defines must be in the matrix - so a new
     * Laravel release that adds rules fails CI until they're implemented or listed as
     * unsupported - and unsupported ones must really be rejected by the parser.
     */
    public function testEveryLaravelRuleIsAccountedFor(): void
    {
        $matrix = $this->matrix();
        $missing = [];

        foreach ((new \ReflectionClass(\Illuminate\Validation\Validator::class))->getMethods() as $method) {
            if (preg_match('/^validate([A-Z]\w*)$/', $method->getName(), $m) !== 1) {
                continue;
            }
            $rule = \Illuminate\Support\Str::snake($m[1]);
            if (!in_array($rule, self::LARAVEL_NON_RULES, true) && !isset($matrix[$rule])) {
                $missing[] = $rule;
            }
        }

        self::assertSame([], $missing, 'Laravel rules missing from the compatibility matrix: ' . implode(', ', $missing));

        foreach ($matrix as $rule => $entry) {
            if ($entry['status'] !== 'unsupported') {
                continue;
            }
            try {
                (new \Vi\Validation\Laravel\LaravelRuleParser())->parse($rule . ':x', 'field');
                self::fail("'{$rule}' is marked unsupported but the parser accepted it");
            } catch (\Vi\Validation\Laravel\UnsupportedRuleException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEveryEntryDeclaresTheRequiredFields(): void
    {
        $problems = [];

        foreach ($this->matrix() as $rule => $entry) {
            foreach (['category', 'status', 'engine', 'native_compilable', 'dependencies', 'laravel_since', 'notes'] as $field) {
                if (!array_key_exists($field, $entry)) {
                    $problems[] = "{$rule}.{$field}";
                }
            }
            if ($entry['status'] === 'partial' && empty($entry['notes'])) {
                $problems[] = "{$rule}: partial without notes";
            }
        }

        self::assertSame([], $problems, 'Incomplete matrix entries: ' . implode(', ', $problems));
    }

    /**
     * The README's "Supported Rules" table advertises exactly the rules the engine supports.
     */
    public function testReadmeSupportedRulesMatchTheMatrix(): void
    {
        $readme = (string) file_get_contents(__DIR__ . '/../../../README.md');
        $start = strpos($readme, '### 🛠 Supported Rules');
        $end = strpos($readme, '### ✅ Parity & Compatibility');
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        preg_match_all('/`([a-z_0-9]+)`/', substr($readme, $start, $end - $start), $m);
        $advertised = array_values(array_unique($m[1]));
        sort($advertised);

        $supported = [];
        foreach ($this->matrix() as $rule => $entry) {
            if ($entry['engine'] && $entry['category'] !== 'meta') {
                $supported[] = $rule;
            }
        }
        sort($supported);

        self::assertSame([], array_values(array_diff($advertised, $supported)), 'README advertises rules the engine does not support');
        self::assertSame([], array_values(array_diff($supported, $advertised)), 'Supported rules missing from the README table');
    }

    public function testGeneratedRuleDocumentIsUpToDate(): void
    {
        self::assertSame(
            \Vi\Validation\Tests\Support\RuleMatrixDocument::render(),
            (string) file_get_contents(\Vi\Validation\Tests\Support\RuleMatrixDocument::path()),
            'docs/rules.md is stale - run `php tests/generate_rule_matrix.php`.'
        );
    }
}
