<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Parity;

use Vi\Validation\Tests\Unit\Parity\Support\ParityTestCase;

/**
 * Explicitly covers nested and wildcard attribute addressing (see _attribute_addressing in
 * resources/compatibility-matrix.json). Depth-2 dot nesting ('parent.child') has full parity
 * with Laravel; wildcards and depth-3+ nesting are not implemented anywhere in
 * CompiledField/ValidatorEngine and are pinned here as documented divergences.
 */
class NestedWildcardParityTest extends ParityTestCase
{
    public function testDepth2NestedRequiredPasses(): void
    {
        $this->assertParity(
            ['address.city' => 'required|string'],
            ['address' => ['city' => 'Springfield']]
        );
    }

    public function testDepth2NestedRequiredFailsWhenMissing(): void
    {
        $this->assertParity(
            ['address.city' => 'required|string'],
            ['address' => ['street' => 'Main St']]
        );
    }

    public function testDepth2NestedRequiredFailsWhenParentMissing(): void
    {
        $this->assertParity(['address.city' => 'required|string'], []);
    }

    public function testDepth2NestedNumericRulePasses(): void
    {
        $this->assertParity(
            ['order.total' => 'required|numeric|min:1'],
            ['order' => ['total' => 25.5]]
        );
    }

    public function testDepth2NestedNumericRuleFails(): void
    {
        $this->assertParity(
            ['order.total' => 'required|numeric|min:1'],
            ['order' => ['total' => 0]]
        );
    }

    public function testDepth2NestedEmailValidation(): void
    {
        $this->assertParity(
            ['contact.email' => 'required|email'],
            ['contact' => ['email' => 'not-an-email']]
        );
    }

    /**
     * Known, unfixed gap: CompiledField only understands exactly one level of dot-nesting.
     * A wildcard segment ('items.*.sku') never resolves against real array data - the field
     * value is always null - so implicit rules (required) unconditionally fail and
     * non-implicit rules unconditionally no-op (silently pass), regardless of what the array
     * actually contains. Laravel instead expands the wildcard per index against real data.
     */
    public function testWildcardRequiredAlwaysFailsEvenWhenEveryElementIsValid(): void
    {
        $data = ['items' => [
            ['sku' => 'ABC-1'],
            ['sku' => 'ABC-2'],
        ]];

        $result = $this->assertDivergence(
            ['items.*.sku' => 'required|string'],
            $data,
            'Wildcard attributes are not implemented: "items.*.sku" never resolves against real'
                . ' data, so required unconditionally fails even though every element has a sku.'
        );

        self::assertFalse($result['laravel_fails'], 'Laravel correctly validates every element and passes.');
        self::assertTrue($result['fast_fails'], 'Fast always fails required on an unresolved wildcard field.');
    }

    public function testWildcardNonImplicitRuleAlwaysNoOpsEvenWhenDataIsInvalid(): void
    {
        $data = ['items' => [
            ['sku' => 'not an email'],
            ['sku' => 'also not an email'],
        ]];

        $result = $this->assertDivergence(
            ['items.*.sku' => 'email'],
            $data,
            'Wildcard attributes are not implemented: a non-implicit rule on "items.*.sku"'
                . ' always sees a null value (unresolved) and is skipped as "empty", so it never'
                . ' actually validates any element.'
        );

        self::assertTrue($result['laravel_fails'], 'Laravel correctly rejects the invalid emails.');
        self::assertFalse($result['fast_fails'], 'Fast silently no-ops and passes.');
    }

    /**
     * Known, unfixed gap: dot-nesting only supports exactly 2 segments. A 3rd-level
     * attribute name is split into parent="a", child="b.c" and then looked up as the
     * literal array key "b.c" (which never exists), so it behaves identically to the
     * wildcard case above - always null.
     */
    public function testDepth3NestedRequiredAlwaysFailsEvenWhenPresent(): void
    {
        $data = ['a' => ['b' => ['c' => 'present']]];

        $result = $this->assertDivergence(
            ['a.b.c' => 'required|string'],
            $data,
            'Depth-3+ dot nesting is not implemented: CompiledField splits on the first dot'
                . ' only, so "a.b.c" looks up the literal key "b.c" inside $data[\'a\'], which'
                . ' never exists, even though $data[\'a\'][\'b\'][\'c\'] is actually present.'
        );

        self::assertFalse($result['laravel_fails'], 'Laravel correctly resolves the 3-level path and passes.');
        self::assertTrue($result['fast_fails'], 'Fast cannot resolve past 2 levels and always fails required.');
    }
}
