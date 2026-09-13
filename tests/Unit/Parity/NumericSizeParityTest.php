<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Parity;

use PHPUnit\Framework\Attributes\Group;
use Vi\Validation\Tests\Unit\Parity\Support\ParityTestCase;

/**
 * Covers RuleId: min, max, size, between, digits, digits_between, multiple_of.
 */
#[Group('laravel')]
class NumericSizeParityTest extends ParityTestCase
{
    public function testMinPassesForNumber(): void
    {
        $this->assertParity(['age' => 'integer|min:18'], ['age' => 18]);
    }

    public function testMinFailsForNumber(): void
    {
        $this->assertParity(['age' => 'integer|min:18'], ['age' => 17]);
    }

    public function testMinPassesForStringLength(): void
    {
        $this->assertParity(['name' => 'string|min:3'], ['name' => 'Ada']);
    }

    public function testMinFailsForStringLength(): void
    {
        $this->assertParity(['name' => 'string|min:3'], ['name' => 'Al']);
    }

    public function testMinFailsForArrayCount(): void
    {
        $this->assertParity(['tags' => 'array|min:2'], ['tags' => ['a']]);
    }

    public function testMaxPassesForNumber(): void
    {
        $this->assertParity(['age' => 'integer|max:65'], ['age' => 65]);
    }

    public function testMaxFailsForNumber(): void
    {
        $this->assertParity(['age' => 'integer|max:65'], ['age' => 66]);
    }

    public function testSizePassesExactStringLength(): void
    {
        $this->assertParity(['code' => 'string|size:5'], ['code' => 'ABCDE']);
    }

    public function testSizeFailsWrongStringLength(): void
    {
        $this->assertParity(['code' => 'string|size:5'], ['code' => 'ABCD']);
    }

    public function testSizeFailsWrongNumber(): void
    {
        $this->assertParity(['count' => 'numeric|size:10'], ['count' => 9]);
    }

    public function testBetweenPassesWithinRange(): void
    {
        $this->assertParity(['age' => 'integer|between:18,65'], ['age' => 30]);
    }

    public function testBetweenFailsBelowRange(): void
    {
        $this->assertParity(['age' => 'integer|between:18,65'], ['age' => 10]);
    }

    public function testBetweenFailsAboveRange(): void
    {
        $this->assertParity(['age' => 'integer|between:18,65'], ['age' => 100]);
    }

    public function testDigitsPasses(): void
    {
        $this->assertParity(['pin' => 'digits:4'], ['pin' => '1234']);
    }

    public function testDigitsFailsWrongLength(): void
    {
        $this->assertParity(['pin' => 'digits:4'], ['pin' => '123']);
    }

    public function testDigitsFailsWithNonDigits(): void
    {
        $this->assertParity(['pin' => 'digits:4'], ['pin' => '12a4']);
    }

    public function testDigitsBetweenPasses(): void
    {
        $this->assertParity(['code' => 'digits_between:3,5'], ['code' => '1234']);
    }

    public function testDigitsBetweenFails(): void
    {
        $this->assertParity(['code' => 'digits_between:3,5'], ['code' => '12']);
    }

    public function testMultipleOfPasses(): void
    {
        $this->assertParity(['qty' => 'numeric|multiple_of:5'], ['qty' => 25]);
    }

    public function testMultipleOfFails(): void
    {
        $this->assertParity(['qty' => 'numeric|multiple_of:5'], ['qty' => 23]);
    }

    public function testMultipleOfFailsWithZeroFactorEdgeCase(): void
    {
        $this->assertParity(['qty' => 'numeric|multiple_of:5'], ['qty' => 0]);
    }
}
