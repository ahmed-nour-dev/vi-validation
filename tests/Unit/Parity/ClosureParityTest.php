<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Parity;

use Vi\Validation\Tests\Unit\Parity\Support\ParityTestCase;

/**
 * Covers RuleId: closure, conditional.
 *
 * Neither is a user-typed rule-string name (see compatibility-matrix.json: both are
 * "not_applicable" for direct string-rule comparison), so they're exercised through their
 * actual usage patterns: an inline Laravel-style closure rule, and Validator::sometimes().
 */
class ClosureParityTest extends ParityTestCase
{
    public function testInlineClosurePassesForValidValue(): void
    {
        $closure = function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value !== 'expected') {
                $fail("The {$attribute} must be exactly 'expected'.");
            }
        };

        $this->assertParity(
            ['code' => ['required', $closure]],
            ['code' => 'expected']
        );
    }

    public function testInlineClosureFailsForInvalidValue(): void
    {
        $closure = function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value !== 'expected') {
                $fail("The {$attribute} must be exactly 'expected'.");
            }
        };

        $this->assertParity(
            ['code' => ['required', $closure]],
            ['code' => 'unexpected']
        );
    }

    public function testInlineClosureCombinedWithStringRules(): void
    {
        $closure = function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && str_contains($value, ' ')) {
                $fail('No spaces allowed.');
            }
        };

        $this->assertParity(
            ['username' => ['required', 'string', 'min:3', $closure]],
            ['username' => 'ada lovelace']
        );
    }

    public function testSometimesAddsRulesWhenCallbackTrue(): void
    {
        $data = ['country' => 'US', 'state' => ''];

        $laravel = $this->laravel($data, ['country' => 'required']);
        $laravel->sometimes('state', 'required', function ($input) {
            return $input->country === 'US';
        });

        $fast = $this->fast($data, ['country' => 'required']);
        $fast->sometimes('state', 'required', function (array $input) {
            return $input['country'] === 'US';
        });

        self::assertSame($laravel->fails(), $fast->fails());
        self::assertTrue($fast->fails());
    }

    public function testSometimesSkipsRulesWhenCallbackFalse(): void
    {
        $data = ['country' => 'FR', 'state' => ''];

        $laravel = $this->laravel($data, ['country' => 'required']);
        $laravel->sometimes('state', 'required', function ($input) {
            return $input->country === 'US';
        });

        $fast = $this->fast($data, ['country' => 'required']);
        $fast->sometimes('state', 'required', function (array $input) {
            return $input['country'] === 'US';
        });

        self::assertSame($laravel->fails(), $fast->fails());
        self::assertFalse($fast->fails());
    }
}
