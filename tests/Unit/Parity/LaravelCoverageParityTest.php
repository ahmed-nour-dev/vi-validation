<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Parity;

use PHPUnit\Framework\Attributes\Group;
use Vi\Validation\Tests\Unit\Parity\Support\ParityTestCase;

/**
 * Covers RuleId: contains, hex_color, in_array, max_digits, min_digits, present_if,
 * present_unless, present_with, present_with_all, prohibited_if_accepted,
 * prohibited_if_declined, required_if_declined - the Laravel rules vi/validation didn't
 * implement at all - plus Laravel's dependent-value matching for every *_if / *_unless rule.
 *
 * Rules that the installed Laravel version doesn't have yet are skipped for it.
 */
#[Group('laravel')]
final class LaravelCoverageParityTest extends ParityTestCase
{
    private function requireLaravelRule(string $rule): void
    {
        $method = 'validate' . str_replace('_', '', ucwords($rule, '_'));
        if (!method_exists(\Illuminate\Validation\Validator::class, $method)) {
            self::markTestSkipped("The installed Laravel version has no '{$rule}' rule.");
        }
    }

    public function testMaxAndMinDigits(): void
    {
        $this->requireLaravelRule('max_digits');
        foreach ([123, '123', '1234', 12.5, '-12', 'abc', '', '0012'] as $value) {
            $this->assertParity(['a' => 'max_digits:3'], ['a' => $value]);
            $this->assertParity(['a' => 'min_digits:3'], ['a' => $value]);
        }
    }

    public function testHexColor(): void
    {
        $this->requireLaravelRule('hex_color');
        foreach (['#fff', '#FFFF', '#a1b2c3', '#a1b2c3d4', 'fff', '#ggg', '#12345', 123] as $value) {
            $this->assertParity(['a' => 'hex_color'], ['a' => $value]);
        }
    }

    public function testContains(): void
    {
        $this->requireLaravelRule('contains');
        $this->assertParity(['a' => 'contains:x,y'], ['a' => ['x', 'y', 'z']]);
        $this->assertParity(['a' => 'contains:x,y'], ['a' => ['x']]);
        $this->assertParity(['a' => 'contains:1'], ['a' => [1, 2]]);
        $this->assertParity(['a' => 'contains:x'], ['a' => 'x']);
    }

    public function testInArray(): void
    {
        $this->requireLaravelRule('in_array');
        $data = ['allowed' => ['red', 'green', ['nested' => 'blue']], 'plain' => 'solo'];
        $this->assertParity(['a' => 'in_array:allowed.*'], $data + ['a' => 'red']);
        $this->assertParity(['a' => 'in_array:allowed.*'], $data + ['a' => 'blue']);
        $this->assertParity(['a' => 'in_array:allowed.*'], $data + ['a' => 'pink']);
        $this->assertParity(['a' => 'in_array:plain'], $data + ['a' => 'solo']);
        $this->assertParity(['a' => 'in_array:missing.*'], $data + ['a' => 'red']);
    }

    public function testPresentIfAndUnless(): void
    {
        $this->requireLaravelRule('present_if');
        $this->assertParity(['a' => 'present_if:t,x,y'], ['t' => 'y']);
        $this->assertParity(['a' => 'present_if:t,x,y'], ['t' => 'y', 'a' => '']);
        $this->assertParity(['a' => 'present_if:t,x,y'], ['t' => 'z']);
        $this->assertParity(['a' => 'present_unless:t,x'], ['t' => 'z']);
        $this->assertParity(['a' => 'present_unless:t,x'], ['t' => 'x']);
        $this->assertParity(['a' => 'present_if:t,true'], ['t' => true]);
    }

    public function testPresentWithAndWithAll(): void
    {
        $this->requireLaravelRule('present_with');
        $this->assertParity(['a' => 'present_with:b,c'], ['b' => null]);
        $this->assertParity(['a' => 'present_with:b,c'], ['b' => null, 'a' => null]);
        $this->assertParity(['a' => 'present_with:b,c'], []);
        $this->assertParity(['a' => 'present_with_all:b,c'], ['b' => 1]);
        $this->assertParity(['a' => 'present_with_all:b,c'], ['b' => 1, 'c' => 2]);
        $this->assertParity(['a' => 'present_with_all:b,c'], ['b' => 1, 'c' => 2, 'a' => '']);
    }

    public function testProhibitedIfAcceptedAndDeclined(): void
    {
        $this->requireLaravelRule('prohibited_if_accepted');
        foreach (['yes', 'no', true, false, 1, 0, 'on', 'off', 'maybe'] as $flag) {
            $this->assertParity(['a' => 'prohibited_if_accepted:t'], ['t' => $flag, 'a' => 'x']);
            $this->assertParity(['a' => 'prohibited_if_declined:t'], ['t' => $flag, 'a' => 'x']);
            $this->assertParity(['a' => 'prohibited_if_declined:t'], ['t' => $flag, 'a' => '']);
        }
    }

    public function testRequiredIfDeclined(): void
    {
        $this->requireLaravelRule('required_if_declined');
        foreach (['no', 'off', false, 0, '0', 'yes', true] as $flag) {
            $this->assertParity(['a' => 'required_if_declined:t'], ['t' => $flag]);
            $this->assertParity(['a' => 'required_if_declined:t'], ['t' => $flag, 'a' => 'x']);
        }
    }

    /**
     * Regression: *_if / *_unless compared the other field strictly against the string
     * parameters, so JSON-typed input (int/bool/null) never matched - e.g. required_if:t,1
     * with t = 1 didn't require anything.
     */
    public function testDependentValuesMatchLaravelForTypedInput(): void
    {
        $others = [1, '1', 1.0, true, false, null, 0, 'x'];
        $rules = [
            'required_if:t,1', 'required_if:t,true', 'required_if:t,false', 'required_if:t,null', 'required_if:t,0',
            'required_unless:t,1', 'prohibited_if:t,1', 'prohibited_unless:t,true', 'accepted_if:t,1,x',
            'declined_if:t,null', 'missing_if:t,1', 'missing_unless:t,x,1',
        ];

        foreach ($rules as $rule) {
            foreach ($others as $other) {
                $this->assertParity(['a' => $rule], ['t' => $other]);
                $this->assertParity(['a' => $rule], ['t' => $other, 'a' => 'yes']);
            }
        }
    }

    public function testMultipleDependentValues(): void
    {
        $this->assertParity(['a' => 'accepted_if:t,x,y'], ['t' => 'y', 'a' => 'no']);
        $this->assertParity(['a' => 'declined_if:t,x,y'], ['t' => 'x', 'a' => 'yes']);
        $this->assertParity(['a' => 'missing_if:t,x,y'], ['t' => 'y', 'a' => 1]);
        $this->assertParity(['a' => 'missing_unless:t,x,y'], ['t' => 'z', 'a' => 1]);
        $this->assertParity(['a' => 'exclude_if:t,x,y|required'], ['t' => 'y']);
        $this->assertParity(['a' => 'exclude_unless:t,x,y|required'], ['t' => 'z']);
    }
}
