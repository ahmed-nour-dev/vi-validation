<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Parity;

use PHPUnit\Framework\Attributes\Group;
use Vi\Validation\Tests\Unit\Parity\Support\ParityTestCase;

/**
 * Covers RuleId: required, nullable, filled, present, missing, bail, sometimes.
 */
#[Group('laravel')]
class CorePresenceParityTest extends ParityTestCase
{
    public function testRequiredPassesWhenPresent(): void
    {
        $this->assertParity(['name' => 'required'], ['name' => 'Ada']);
    }

    public function testRequiredFailsWhenMissing(): void
    {
        $this->assertParity(['name' => 'required'], []);
    }

    public function testRequiredFailsWhenNull(): void
    {
        $this->assertParity(['name' => 'required'], ['name' => null]);
    }

    public function testRequiredFailsWhenEmptyString(): void
    {
        $this->assertParity(['name' => 'required'], ['name' => '']);
    }

    public function testRequiredFailsWhenEmptyArray(): void
    {
        $this->assertParity(['tags' => 'required'], ['tags' => []]);
    }

    public function testRequiredPassesWithZeroString(): void
    {
        $this->assertParity(['count' => 'required'], ['count' => '0']);
    }

    public function testRequiredPassesWithBooleanFalse(): void
    {
        $this->assertParity(['flag' => 'required'], ['flag' => false]);
    }

    public function testNullablePassesWithNull(): void
    {
        $this->assertParity(['bio' => 'nullable|string|min:3'], ['bio' => null]);
    }

    public function testNullableStillValidatesNonNullValue(): void
    {
        $this->assertParity(['bio' => 'nullable|string|min:3'], ['bio' => 'ab']);
    }

    public function testFilledPassesWhenAbsent(): void
    {
        $this->assertParity(['nickname' => 'filled'], []);
    }

    public function testFilledFailsWhenPresentButEmpty(): void
    {
        $this->assertParity(['nickname' => 'filled'], ['nickname' => '']);
    }

    public function testFilledPassesWhenPresentAndNonEmpty(): void
    {
        $this->assertParity(['nickname' => 'filled'], ['nickname' => 'Ada']);
    }

    public function testPresentFailsWhenMissing(): void
    {
        $this->assertParity(['nickname' => 'present'], []);
    }

    public function testPresentPassesWhenNull(): void
    {
        $this->assertParity(['nickname' => 'present'], ['nickname' => null]);
    }

    public function testMissingPassesWhenAbsent(): void
    {
        $this->assertParity(['secret' => 'missing'], []);
    }

    public function testMissingFailsWhenPresent(): void
    {
        $this->assertParity(['secret' => 'missing'], ['secret' => 'anything']);
    }

    public function testBailStopsAfterFirstFailure(): void
    {
        $this->assertParity(
            ['email' => 'bail|required|email|min:50'],
            ['email' => 'not-an-email']
        );
    }

    public function testSometimesSkipsValidationWhenFieldAbsent(): void
    {
        $this->assertParity(['age' => 'sometimes|integer|min:18'], []);
    }

    public function testSometimesStillValidatesWhenFieldPresent(): void
    {
        $this->assertParity(['age' => 'sometimes|integer|min:18'], ['age' => 10]);
    }

    public function testMultipleFieldsMixedPassAndFail(): void
    {
        $this->assertParity(
            ['name' => 'required|string', 'age' => 'required|integer'],
            ['name' => 'Ada', 'age' => 'not-a-number']
        );
    }
}
