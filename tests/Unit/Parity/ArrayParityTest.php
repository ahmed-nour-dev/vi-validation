<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Parity;

use Vi\Validation\Tests\Unit\Parity\Support\ParityTestCase;

/**
 * Covers RuleId: array, distinct, required_array_keys.
 */
class ArrayParityTest extends ParityTestCase
{
    public function testArrayWithAllowedKeysPasses(): void
    {
        $this->assertParity(
            ['options' => 'array:color,size'],
            ['options' => ['color' => 'red', 'size' => 'M']]
        );
    }

    /**
     * Laravel's `distinct` validates one array element at a time via wildcard expansion
     * (`tags.*` => `distinct`, called once per index against its siblings). vi/validation
     * has no wildcard support (see `_attribute_addressing` in the compatibility matrix) so
     * its DistinctRule instead validates the whole array value directly (`tags` =>
     * `distinct`). These are the two idioms a Laravel user and a vi/validation user would
     * each actually write for "no duplicates in this array" — compared via
     * assertEquivalentParity() rather than assertParity() since the rule shapes differ.
     */
    public function testDistinctPasses(): void
    {
        $this->assertEquivalentParity(
            ['tags.*' => 'distinct'],
            ['tags' => 'array|distinct'],
            ['tags' => ['a', 'b', 'c']]
        );
    }

    public function testDistinctFailsWithDuplicates(): void
    {
        $this->assertEquivalentParity(
            ['ids.*' => 'distinct'],
            ['ids' => 'array|distinct'],
            ['ids' => [1, 2, 2]]
        );
    }

    public function testDistinctStrictConsidersTypeDifferences(): void
    {
        $this->assertEquivalentParity(
            ['ids.*' => 'distinct:strict'],
            ['ids' => 'array|distinct:strict'],
            ['ids' => [1, '1']]
        );
    }

    public function testDistinctIgnoreCasePasses(): void
    {
        $this->assertEquivalentParity(
            ['tags.*' => 'distinct:ignore_case'],
            ['tags' => 'array|distinct:ignore_case'],
            ['tags' => ['Apple', 'Banana']]
        );
    }

    public function testDistinctIgnoreCaseFails(): void
    {
        $this->assertEquivalentParity(
            ['tags.*' => 'distinct:ignore_case'],
            ['tags' => 'array|distinct:ignore_case'],
            ['tags' => ['Apple', 'apple']]
        );
    }

    public function testRequiredArrayKeysPasses(): void
    {
        $this->assertParity(
            ['address' => 'required_array_keys:street,city'],
            ['address' => ['street' => 'Main St', 'city' => 'Springfield']]
        );
    }

    public function testRequiredArrayKeysFailsWhenKeyMissing(): void
    {
        $this->assertParity(
            ['address' => 'required_array_keys:street,city'],
            ['address' => ['street' => 'Main St']]
        );
    }
}
