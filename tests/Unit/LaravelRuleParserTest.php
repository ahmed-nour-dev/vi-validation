<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Vi\Validation\Execution\ErrorCollector;
use Vi\Validation\Execution\ValidationContext;
use Vi\Validation\Laravel\LaravelRuleParser;
use Vi\Validation\Rules\ClosureRule;
use Vi\Validation\Rules\RequiredRule;

#[Group('laravel')]
class LaravelRuleParserTest extends TestCase
{
    private LaravelRuleParser $parser;

    protected function setUp(): void
    {
        $this->parser = new LaravelRuleParser();
    }

    public function testParseRequiredRule(): void
    {
        $rules = $this->parser->parse('required');
        $this->assertCount(1, $rules);
    }

    public function testParseMultipleRules(): void
    {
        $rules = $this->parser->parse('required|string|max:255');
        $this->assertCount(3, $rules);
    }

    public function testParseRulesWithParameters(): void
    {
        $rules = $this->parser->parse('between:1,10');
        $this->assertCount(1, $rules);
    }

    public function testParseNewCoreTypeRules(): void
    {
        $rules = $this->parser->parse('numeric|boolean|array|date|json');
        $this->assertCount(5, $rules);
    }

    public function testParseNewStringRules(): void
    {
        $rules = $this->parser->parse('alpha|alpha_num|url|uuid|ip');
        $this->assertCount(5, $rules);
    }

    public function testParseNewComparisonRules(): void
    {
        $rules = $this->parser->parse('in:a,b,c|not_in:x,y,z|confirmed|same:other|different:another');
        $this->assertCount(5, $rules);
    }

    public function testParseDateWithFormat(): void
    {
        $rules = $this->parser->parse('date_format:Y-m-d');
        $this->assertCount(1, $rules);
    }

    public function testParseIpVersions(): void
    {
        $rules = $this->parser->parse('ip|ipv4|ipv6');
        $this->assertCount(3, $rules);
    }

    public function testParseFileRules(): void
    {
        $rules = $this->parser->parse('file|image|mimes:jpg,png');
        $this->assertCount(3, $rules);
    }

    public function testParseArrayDefinition(): void
    {
        $rules = $this->parser->parse(['required', 'string', 'max:100']);
        $this->assertCount(3, $rules);
    }

    public function testParseUnknownRuleReturnsNull(): void
    {
        $rules = $this->parser->parse('unknown_rule');
        $this->assertCount(0, $rules);
    }

    public function testParseSizeRules(): void
    {
        $rules = $this->parser->parse('min:5|max:10|size:7|between:3,8');
        $this->assertCount(4, $rules);
    }

    public function testParseClosureRule(): void
    {
        $closure = function ($attribute, $value, $fail) {
            if ($value === 'invalid') {
                $fail('The value is invalid.');
            }
        };

        $rules = $this->parser->parse([$closure]);
        $this->assertCount(1, $rules);
        $this->assertInstanceOf(ClosureRule::class, $rules[0]);
    }

    public function testParseClosureWithOtherRules(): void
    {
        $closure = function ($attribute, $value, $fail) {
            if ($value !== 'allowed') {
                $fail('The value must be "allowed".');
            }
        };

        $rules = $this->parser->parse([
            'required',
            'string',
            $closure,
        ]);

        $this->assertCount(3, $rules);
        $this->assertInstanceOf(ClosureRule::class, $rules[2]);
    }

    public function testClosureRulePasses(): void
    {
        $closure = function ($attribute, $value, $fail) {
            if ($value !== 'valid') {
                $fail('The value is not valid.');
            }
        };

        $rule = new ClosureRule($closure);
        $context = new ValidationContext(['field' => 'valid'], new ErrorCollector());

        $result = $rule->validate('valid', 'field', $context);
        $this->assertNull($result);
    }

    public function testClosureRuleFails(): void
    {
        $closure = function ($attribute, $value, $fail) {
            if ($value !== 'valid') {
                $fail('The :attribute is not valid.');
            }
        };

        $rule = new ClosureRule($closure);
        $context = new ValidationContext(['field' => 'invalid'], new ErrorCollector());

        $result = $rule->validate('invalid', 'field', $context);
        $this->assertNotNull($result);
        $this->assertEquals('closure', $result['rule']);
        $this->assertEquals('The :attribute is not valid.', $result['message']);
    }

    public function testParseRuleInterfaceInstance(): void
    {
        $requiredRule = new RequiredRule();

        $rules = $this->parser->parse([
            $requiredRule,
            'string',
        ]);

        $this->assertCount(2, $rules);
        $this->assertSame($requiredRule, $rules[0]);
    }
}
