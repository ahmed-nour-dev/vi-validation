<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Parity;

use Illuminate\Validation\Rules\Enum;
use PHPUnit\Framework\Attributes\Group;
use Vi\Validation\Tests\Unit\Parity\Support\ParityTestCase;

enum StatusFixture: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

/**
 * Covers RuleId: string, integer, numeric, boolean, array, list, date, json, enum, decimal.
 */
#[Group('laravel')]
class TypeParityTest extends ParityTestCase
{
    public function testStringPasses(): void
    {
        $this->assertParity(['name' => 'string'], ['name' => 'Ada']);
    }

    public function testStringFailsWithInteger(): void
    {
        $this->assertParity(['name' => 'string'], ['name' => 123]);
    }

    public function testIntegerPassesWithNumericString(): void
    {
        $this->assertParity(['age' => 'integer'], ['age' => '18']);
    }

    public function testIntegerFailsWithFloatString(): void
    {
        $this->assertParity(['age' => 'integer'], ['age' => '18.5']);
    }

    public function testNumericPassesWithFloat(): void
    {
        $this->assertParity(['price' => 'numeric'], ['price' => 19.99]);
    }

    public function testNumericFailsWithNonNumericString(): void
    {
        $this->assertParity(['price' => 'numeric'], ['price' => 'abc']);
    }

    public function testBooleanPassesWithZeroOne(): void
    {
        $this->assertParity(['flag' => 'boolean'], ['flag' => 0]);
    }

    public function testBooleanFailsWithArbitraryString(): void
    {
        $this->assertParity(['flag' => 'boolean'], ['flag' => 'maybe']);
    }

    public function testArrayPasses(): void
    {
        $this->assertParity(['tags' => 'array'], ['tags' => ['a', 'b']]);
    }

    public function testArrayFailsWithString(): void
    {
        $this->assertParity(['tags' => 'array'], ['tags' => 'a,b']);
    }

    public function testListPassesWithSequentialArray(): void
    {
        // 'list' was added to Laravel in v11; on v10 it isn't a recognized rule name.
        $this->skipUnlessLaravelValidationAtLeast('11.0.0');
        $this->assertParity(['tags' => 'list'], ['tags' => ['a', 'b', 'c']]);
    }

    public function testListFailsWithAssociativeArray(): void
    {
        $this->skipUnlessLaravelValidationAtLeast('11.0.0');
        $this->assertParity(['tags' => 'list'], ['tags' => ['x' => 'a', 'y' => 'b']]);
    }

    public function testDatePassesWithValidDateString(): void
    {
        $this->assertParity(['dob' => 'date'], ['dob' => '2024-01-15']);
    }

    public function testDateFailsWithInvalidString(): void
    {
        $this->assertParity(['dob' => 'date'], ['dob' => 'not-a-date']);
    }

    public function testJsonPassesWithValidJsonString(): void
    {
        $this->assertParity(['payload' => 'json'], ['payload' => '{"a":1}']);
    }

    public function testJsonFailsWithInvalidJsonString(): void
    {
        $this->assertParity(['payload' => 'json'], ['payload' => '{invalid']);
    }

    public function testDecimalPassesWithinRange(): void
    {
        $this->assertParity(['amount' => 'decimal:0,2'], ['amount' => '19.99']);
    }

    public function testDecimalFailsWithTooManyPlaces(): void
    {
        $this->assertParity(['amount' => 'decimal:0,2'], ['amount' => '19.999']);
    }

    public function testDecimalFailsWithNoDecimalPlacesWhenMinimumRequired(): void
    {
        $this->assertParity(['amount' => 'decimal:1,2'], ['amount' => '19']);
    }

    public function testEnumPassesWithValidBackedValue(): void
    {
        $rules = ['status' => 'enum:' . StatusFixture::class];
        $laravelRules = ['status' => [new Enum(StatusFixture::class)]];
        $data = ['status' => 'active'];

        $laravel = $this->laravel($data, $laravelRules);
        $fast = $this->fast($data, $rules);

        self::assertSame($laravel->fails(), $fast->fails());
    }

    public function testEnumFailsWithInvalidValue(): void
    {
        $rules = ['status' => 'enum:' . StatusFixture::class];
        $laravelRules = ['status' => [new Enum(StatusFixture::class)]];
        $data = ['status' => 'unknown'];

        $laravel = $this->laravel($data, $laravelRules);
        $fast = $this->fast($data, $rules);

        self::assertSame($laravel->fails(), $fast->fails());
    }
}
