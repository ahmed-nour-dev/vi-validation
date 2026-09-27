<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Parity;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ImplicitRule;
use Illuminate\Contracts\Validation\InvokableRule;
use Illuminate\Contracts\Validation\Rule as LegacyRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use PHPUnit\Framework\Attributes\Group;
use Vi\Validation\Laravel\FastValidatorFactory;
use Vi\Validation\Laravel\LaravelRuleAdapter;
use Vi\Validation\Tests\Unit\Parity\Support\ParityTestCase;

/**
 * Laravel rule objects (Rule::in(), custom ValidationRule classes, ...) and Laravel's rule
 * parameter grammar. These used to crash with a TypeError or be split incorrectly.
 */
#[Group('laravel')]
final class RuleObjectParityTest extends ParityTestCase
{
    public function testStringableBuiltInRuleObjects(): void
    {
        $this->assertParity(['a' => [Rule::in(['x', 'y'])]], ['a' => 'x']);
        $this->assertParity(['a' => [Rule::in(['x', 'y'])]], ['a' => 'z']);
        $this->assertParity(['a' => ['required', Rule::notIn(['bad'])]], ['a' => 'bad']);
        $this->assertParity(['a' => ['required', Rule::notIn(['bad'])]], ['a' => 'good']);
        // Values containing commas and quotes survive Rule::in()'s CSV-quoted string form.
        $this->assertParity(['a' => [Rule::in(['a,b', 'c "d"'])]], ['a' => 'a,b']);
        $this->assertParity(['a' => [Rule::in(['a,b', 'c "d"'])]], ['a' => 'c "d"']);
        $this->assertParity(['a' => [Rule::in(['a,b', 'c "d"'])]], ['a' => 'a']);
        $this->assertParity(['a' => [Rule::requiredIf(true)]], ['a' => '']);
        $this->assertParity(['a' => [Rule::requiredIf(false)]], ['a' => '']);
        $this->assertParity(['a' => [Rule::requiredIf(static fn (): bool => true)]], []);
        $this->assertParity(['a' => [Rule::prohibitedIf(true)]], ['a' => 'x']);
    }

    public function testQuotedAndCommaContainingParameters(): void
    {
        $this->assertParity(['a' => 'in:"a,b",c'], ['a' => 'a,b']);
        $this->assertParity(['a' => 'in:"a,b",c'], ['a' => 'a']);
        // A regex is one parameter even when it contains commas.
        $this->assertParity(['a' => 'regex:/^a{1,3}$/'], ['a' => 'aa']);
        $this->assertParity(['a' => 'regex:/^a{1,3}$/'], ['a' => 'aaaa']);
        $this->assertParity(['a' => ['not_regex:/^x{2,}$/']], ['a' => 'xxx']);
    }

    public function testCustomValidationRuleObject(): void
    {
        $this->assertParity(['a' => [new UppercaseValidationRule()]], ['a' => 'ABC']);
        $this->assertParity(['a' => [new UppercaseValidationRule()]], ['a' => 'abc']);

        $wrapper = (new FastValidatorFactory())->make(['first_name' => 'abc'], ['first_name' => [new UppercaseValidationRule()]]);
        $this->assertSame('The first name must be uppercase.', $wrapper->errors()->first('first_name'));
    }

    public function testLegacyRuleAndInvokableRuleObjects(): void
    {
        $this->assertParity(['a' => [new EvenLegacyRule()]], ['a' => 4]);
        $this->assertParity(['a' => [new EvenLegacyRule()]], ['a' => 3]);
        $this->assertParity(['a' => [new NotFooInvokableRule()]], ['a' => 'foo']);
        $this->assertParity(['a' => [new NotFooInvokableRule()]], ['a' => 'bar']);

        $wrapper = (new FastValidatorFactory())->make(['a' => 3], ['a' => [new EvenLegacyRule()]]);
        $this->assertSame('The a must be even.', $wrapper->errors()->first('a'));
    }

    public function testDataAwareAndImplicitRuleObjects(): void
    {
        $this->assertParity(['end' => [new AfterStartRule()]], ['start' => 5, 'end' => 9]);
        $this->assertParity(['end' => [new AfterStartRule()]], ['start' => 5, 'end' => 2]);

        // Implicit rules run even when the value is missing/empty; non-implicit ones don't.
        $this->assertParity(['a' => [new AlwaysFailImplicitRule()]], []);
        $this->assertParity(['a' => [new AlwaysFailImplicitRule()]], ['a' => '']);
        $this->assertParity(['a' => [new UppercaseValidationRule()]], ['a' => '']);
    }

    public function testFailMessagesCanBeTranslatedFluently(): void
    {
        $rule = new class implements ValidationRule {
            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                $fail('The :attribute is :what.')->translate(['what' => 'wrong']);
            }
        };

        $wrapper = (new FastValidatorFactory())->make(['a' => 'x'], ['a' => [$rule]]);

        $this->assertTrue($wrapper->fails());
        $this->assertNotSame('', (string) $wrapper->errors()->first('a'));
    }

    public function testAdaptedRulesAreNeverNativeAndHaveUnstableOrContentIdentity(): void
    {
        $wrapper = (new FastValidatorFactory())->make(['a' => 'x'], ['a' => [new UppercaseValidationRule()]]);
        $diagnostics = $wrapper->diagnostics();

        $this->assertFalse($diagnostics->nativeCompatible);
        $this->assertTrue(LaravelRuleAdapter::supports(new UppercaseValidationRule()));
        $this->assertFalse(LaravelRuleAdapter::supports(new \stdClass()));
    }
}

final class UppercaseValidationRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || strtoupper($value) !== $value) {
            $fail('The :attribute must be uppercase.');
        }
    }
}

final class EvenLegacyRule implements LegacyRule
{
    public function passes($attribute, $value): bool
    {
        return is_int($value) && $value % 2 === 0;
    }

    public function message(): string
    {
        return 'The :attribute must be even.';
    }
}

final class NotFooInvokableRule implements InvokableRule
{
    public function __invoke($attribute, $value, $fail): void
    {
        if ($value === 'foo') {
            $fail('The :attribute must not be foo.');
        }
    }
}

final class AfterStartRule implements ValidationRule, DataAwareRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value <= ($this->data['start'] ?? 0)) {
            $fail('The :attribute must be after start.');
        }
    }
}

final class AlwaysFailImplicitRule implements LegacyRule, ImplicitRule
{
    public function passes($attribute, $value): bool
    {
        return false;
    }

    public function message(): string
    {
        return 'Nope.';
    }
}
