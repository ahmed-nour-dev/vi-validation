<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Property;

use PHPUnit\Framework\Attributes\Group;
use Vi\Validation\Tests\Unit\Parity\Support\ParityTestCase;

/**
 * Regression tests for every bug the property/fuzz tests (PropertyTest) discovered. Each case
 * is asserted against Laravel and, when natively compilable, native vs engine.
 */
#[Group('property')]
#[Group('laravel')]
final class FuzzRegressionTest extends ParityTestCase
{
    /**
     * `sometimes` on a nested field: the native compiler checked array_key_exists('p.q', $data)
     * on the top-level array, so the field was always treated as absent natively.
     */
    public function testSometimesOnNestedFieldsMatchesEngineAndLaravel(): void
    {
        $this->assertParity(['p.q' => 'sometimes|ipv4'], ['p' => ['q' => 'off']]);
        $this->assertParity(['p.q' => 'sometimes|required|alpha_num'], ['p' => ['q' => '_']]);
        $this->assertParity(['p.q' => 'sometimes|required'], ['p' => []]);
        $this->assertParity(['p.q' => 'sometimes|required'], ['p' => 'scalar']);
    }

    /**
     * Paths deeper than two segments: the engine looked up $data['a']['b.c'] while the native
     * path walked every segment, so they disagreed (and the engine disagreed with Laravel).
     */
    public function testThreeLevelPathsMatchEngineNativeAndLaravel(): void
    {
        $this->assertParity(['p.r.s' => 'boolean|alpha_dash'], ['p' => ['r' => ['s' => '1.2.3']]]);
        $this->assertParity(['p.r.s' => 'required|integer'], ['p' => ['r' => ['s' => 3]]]);
        $this->assertParity(['p.r.s' => 'nullable|integer'], ['p' => ['r' => ['s' => null]]]);
    }

    /**
     * `in` / `not_in` with an array value raised "Array to string conversion".
     */
    public function testInAndNotInWithArrayValues(): void
    {
        $this->assertParity(['a' => 'in:a,1,true'], ['a' => ['c' => 'x', 'x' => true]]);
        $this->assertParity(['a' => 'not_in:x'], ['a' => ['nested' => 'y']]);
        $this->assertParity(['tags' => 'array|in:php,go'], ['tags' => ['php', 'go']]);
        $this->assertParity(['tags' => 'array|in:php,go'], ['tags' => ['php', 'rust']]);
        $this->assertParity(['tags' => 'array|not_in:rust'], ['tags' => ['php', 'go']]);
        $this->assertParity(['tags' => 'array|not_in:rust'], ['tags' => ['php', 'rust']]);
        $this->assertParity(['tags' => 'array|not_in:rust,php'], ['tags' => ['php', 'rust']]);
        $this->assertParity(['tags' => 'array|in:php'], ['tags' => [['php']]]);
    }

    /**
     * Date rules threw ValueError ("must not contain any null bytes") on strings with NUL bytes.
     */
    public function testDateRulesRejectNulBytesInsteadOfThrowing(): void
    {
        foreach (['date_format:Y-m-d', 'date', 'after:2020-01-01', 'before:2030-01-01', 'after_or_equal:2020-01-01', 'before_or_equal:2030-01-01', 'date_equals:2024-01-01'] as $rule) {
            foreach (["2024-01-01\0", "\0", "x\0y"] as $value) {
                try {
                    $this->laravel(['a' => $value], ['a' => $rule])->passes();
                } catch (\ValueError) {
                    // Older Laravel 10 releases throw here themselves; vi/validation must still
                    // reject the value rather than throw.
                    $result = (new \Vi\Validation\SchemaValidator(\Vi\Validation\Validator::fromRules(['a' => $rule])))->validate(['a' => $value]);
                    $this->assertFalse($result->isValid(), "{$rule} " . json_encode($value));
                    continue;
                }
                $this->assertParity(['a' => $rule], ['a' => $value]);
            }
        }
    }

    /**
     * `distinct` raised "Array to string conversion" for arrays containing arrays.
     */
    public function testDistinctWithNestedArrays(): void
    {
        // Note: on a non-wildcard field Laravel's `distinct` never fails (it compares wildcard
        // siblings), while vi/validation checks the array's own elements - a documented
        // difference. What regressed was the crash, so assert behaviour directly.
        $validator = new \Vi\Validation\SchemaValidator(\Vi\Validation\Validator::fromRules(['a' => 'distinct']));

        $this->assertTrue($validator->validate(['a' => [18, []]])->isValid());
        $this->assertTrue($validator->validate(['a' => [true, 'x', [], '"str"']])->isValid());
        $this->assertFalse($validator->validate(['a' => [[], []]])->isValid());
        $this->assertFalse($validator->validate(['a' => [['x' => 1], ['x' => 1]]])->isValid());
        $this->assertTrue($validator->validate(['a' => [['x' => 1], ['x' => 2]]])->isValid());
    }

    /**
     * Laravel treats strings that are empty after trim() as empty: `required`/`filled` rejected
     * them in Laravel but passed here, and non-implicit rules failed them here but were skipped
     * by Laravel.
     */
    public function testWhitespaceOnlyStringsAreEmptyLikeLaravel(): void
    {
        foreach ([' ', "\t\n", "  \r ", "\0", "\x0B"] as $blank) {
            foreach (['required', 'filled', 'prohibited', 'email', 'integer', 'min:3', 'string|min:3', 'present', 'required_with:b', 'required_without:c', 'nullable|email'] as $rule) {
                $this->assertParity(['a' => $rule], ['a' => $blank, 'b' => 'x']);
            }
        }
        // Blank strings in *other* fields referenced by conditional rules count as empty too.
        $this->assertParity(['a' => 'required_with:b'], ['b' => '   ']);
        $this->assertParity(['a' => 'required_without:b'], ['b' => "\t"]);
    }

    /**
     * `date` only used strtotime(); Laravel additionally requires date_parse() to yield a real
     * calendar date, so e.g. "x" (a military time zone) is not a date.
     */
    public function testDateRequiresARealCalendarDate(): void
    {
        foreach (['x', 'x\0y', 'now', '2024-02-30', '2024-02-29', '+1 week', '1700000000', 'Z', 'tomorrow'] as $value) {
            $this->assertParity(['a' => 'date'], ['a' => $value]);
        }
    }
}
