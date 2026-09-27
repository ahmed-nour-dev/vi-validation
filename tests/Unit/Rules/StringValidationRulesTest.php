<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Rules;

use PHPUnit\Framework\TestCase;
use Vi\Validation\Execution\ErrorCollector;
use Vi\Validation\Execution\ValidationContext;
use Vi\Validation\Rules\AlphaRule;
use Vi\Validation\Rules\AlphanumericRule;
use Vi\Validation\Rules\RegexRule;
use Vi\Validation\Rules\UrlRule;
use Vi\Validation\Rules\UuidRule;
use Vi\Validation\Rules\IpRule;

class StringValidationRulesTest extends TestCase
{
    private function createContext(array $data = []): ValidationContext
    {
        return new ValidationContext($data, new ErrorCollector());
    }

    // AlphaRule Tests
    public function testAlphaRulePassesWithLettersOnly(): void
    {
        $rule = new AlphaRule();
        $this->assertNull($rule->validate('HelloWorld', 'field', $this->createContext()));
    }

    public function testAlphaRulePassesWithUnicodeLetters(): void
    {
        $rule = new AlphaRule();
        $this->assertNull($rule->validate('ÄÖÜéèà', 'field', $this->createContext()));
    }

    public function testAlphaRuleFailsWithNumbers(): void
    {
        $rule = new AlphaRule();
        $result = $rule->validate('Hello123', 'field', $this->createContext());
        $this->assertEquals(['rule' => 'alpha'], $result);
    }

    // AlphanumericRule Tests
    public function testAlphanumericRulePassesWithLettersAndNumbers(): void
    {
        $rule = new AlphanumericRule();
        $this->assertNull($rule->validate('Hello123', 'field', $this->createContext()));
    }

    public function testAlphanumericRuleFailsWithSpecialChars(): void
    {
        $rule = new AlphanumericRule();
        $result = $rule->validate('Hello@123', 'field', $this->createContext());
        $this->assertEquals(['rule' => 'alpha_num'], $result);
    }

    // RegexRule Tests
    public function testRegexRulePassesWithMatchingPattern(): void
    {
        $rule = new RegexRule('/^[A-Z]{3}\d{3}$/');
        $this->assertNull($rule->validate('ABC123', 'field', $this->createContext()));
    }

    public function testRegexRuleFailsWithNonMatchingPattern(): void
    {
        $rule = new RegexRule('/^[A-Z]{3}\d{3}$/');
        $result = $rule->validate('AB123', 'field', $this->createContext());
        $this->assertEquals(['rule' => 'regex'], $result);
    }

    // UrlRule Tests
    public function testUrlRulePassesWithValidUrl(): void
    {
        $rule = new UrlRule();
        $this->assertNull($rule->validate('https://example.com', 'field', $this->createContext()));
    }

    public function testUrlRulePassesWithUrlPath(): void
    {
        $rule = new UrlRule();
        $this->assertNull($rule->validate('https://example.com/path?query=1', 'field', $this->createContext()));
    }

    public function testUrlRuleFailsWithInvalidUrl(): void
    {
        $rule = new UrlRule();
        $result = $rule->validate('not-a-url', 'field', $this->createContext());
        $this->assertEquals(['rule' => 'url'], $result);
    }

    // UuidRule Tests
    public function testUuidRulePassesWithValidUuid(): void
    {
        $rule = new UuidRule();
        $this->assertNull($rule->validate('550e8400-e29b-41d4-a716-446655440000', 'field', $this->createContext()));
    }

    public function testUuidRuleFailsWithInvalidUuid(): void
    {
        $rule = new UuidRule();
        $result = $rule->validate('not-a-uuid', 'field', $this->createContext());
        $this->assertEquals(['rule' => 'uuid'], $result);
    }

    // IpRule Tests
    public function testIpRulePassesWithValidIpv4(): void
    {
        $rule = new IpRule();
        $this->assertNull($rule->validate('192.168.1.1', 'field', $this->createContext()));
    }

    public function testIpRulePassesWithValidIpv6(): void
    {
        $rule = new IpRule();
        $this->assertNull($rule->validate('::1', 'field', $this->createContext()));
    }

    public function testIpRuleFailsWithInvalidIp(): void
    {
        $rule = new IpRule();
        $result = $rule->validate('256.256.256.256', 'field', $this->createContext());
        $this->assertEquals(['rule' => 'ip'], $result);
    }

    public function testIpv4OnlyRule(): void
    {
        $rule = new IpRule('v4');
        $this->assertNull($rule->validate('192.168.1.1', 'field', $this->createContext()));
        
        $result = $rule->validate('::1', 'field', $this->createContext());
        $this->assertEquals(['rule' => 'ipv4'], $result);
    }

    public function testIpv6OnlyRule(): void
    {
        $rule = new IpRule('v6');
        $this->assertNull($rule->validate('::1', 'field', $this->createContext()));
        
        $result = $rule->validate('192.168.1.1', 'field', $this->createContext());
        $this->assertEquals(['rule' => 'ipv6'], $result);
    }
}
