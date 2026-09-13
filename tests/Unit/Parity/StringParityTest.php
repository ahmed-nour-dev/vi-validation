<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Parity;

use Vi\Validation\Tests\Unit\Parity\Support\ParityTestCase;

/**
 * Covers RuleId: email, url, active_url, ip, ipv4, ipv6, mac_address, uuid, ulid, alpha,
 * alpha_dash, alpha_num, ascii, regex, not_regex, starts_with, ends_with, doesnt_start_with,
 * doesnt_end_with, lowercase, uppercase.
 */
class StringParityTest extends ParityTestCase
{
    public function testEmailPasses(): void
    {
        $this->assertParity(['email' => 'email'], ['email' => 'ada@example.com']);
    }

    public function testEmailFails(): void
    {
        $this->assertParity(['email' => 'email'], ['email' => 'not-an-email']);
    }

    public function testUrlPasses(): void
    {
        $this->assertParity(['site' => 'url'], ['site' => 'https://example.com/path']);
    }

    public function testUrlFails(): void
    {
        $this->assertParity(['site' => 'url'], ['site' => 'not a url']);
    }

    public function testIpPassesWithIpv4(): void
    {
        $this->assertParity(['addr' => 'ip'], ['addr' => '192.168.1.1']);
    }

    public function testIpPassesWithIpv6(): void
    {
        $this->assertParity(['addr' => 'ip'], ['addr' => '::1']);
    }

    public function testIpFailsWithGarbage(): void
    {
        $this->assertParity(['addr' => 'ip'], ['addr' => 'not-an-ip']);
    }

    public function testIpv4RejectsIpv6AddressInLaravelButNotFast(): void
    {
        // Known bug, not fixed by issue #5 (see resources/compatibility-matrix.json: "ipv4").
        // LaravelRuleParser has no case for the "ipv4" alias, so it silently builds a
        // version-unrestricted IpRule instead of Laravel's version-restricted check.
        $result = $this->assertDivergence(
            ['addr' => 'ipv4'],
            ['addr' => '::1'],
            'ipv4 rule string loses version restriction in LaravelRuleParser; behaves like plain ip.'
        );

        self::assertTrue($result['laravel_fails'], 'Laravel correctly rejects an IPv6 address for ipv4.');
        self::assertFalse($result['fast_fails'], 'Fast incorrectly accepts it due to the known parser bug.');
    }

    public function testIpv6RejectsIpv4AddressInLaravelButNotFast(): void
    {
        $result = $this->assertDivergence(
            ['addr' => 'ipv6'],
            ['addr' => '192.168.1.1'],
            'ipv6 rule string loses version restriction in LaravelRuleParser; behaves like plain ip.'
        );

        self::assertTrue($result['laravel_fails'], 'Laravel correctly rejects an IPv4 address for ipv6.');
        self::assertFalse($result['fast_fails'], 'Fast incorrectly accepts it due to the known parser bug.');
    }

    public function testMacAddressPasses(): void
    {
        $this->assertParity(['mac' => 'mac_address'], ['mac' => '00:1B:44:11:3A:B7']);
    }

    public function testMacAddressFails(): void
    {
        $this->assertParity(['mac' => 'mac_address'], ['mac' => 'not-a-mac']);
    }

    public function testUuidPasses(): void
    {
        $this->assertParity(['id' => 'uuid'], ['id' => '550e8400-e29b-41d4-a716-446655440000']);
    }

    public function testUuidFails(): void
    {
        $this->assertParity(['id' => 'uuid'], ['id' => 'not-a-uuid']);
    }

    public function testUlidPasses(): void
    {
        $this->assertParity(['id' => 'ulid'], ['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']);
    }

    public function testUlidFails(): void
    {
        $this->assertParity(['id' => 'ulid'], ['id' => 'not-a-ulid']);
    }

    public function testAlphaPasses(): void
    {
        $this->assertParity(['name' => 'alpha'], ['name' => 'HelloWorld']);
    }

    public function testAlphaFailsWithDigits(): void
    {
        $this->assertParity(['name' => 'alpha'], ['name' => 'Hello123']);
    }

    public function testAlphaDashPasses(): void
    {
        $this->assertParity(['slug' => 'alpha_dash'], ['slug' => 'hello-world_123']);
    }

    public function testAlphaDashFails(): void
    {
        $this->assertParity(['slug' => 'alpha_dash'], ['slug' => 'hello world!']);
    }

    public function testAlphaNumPasses(): void
    {
        $this->assertParity(['code' => 'alpha_num'], ['code' => 'Hello123']);
    }

    public function testAlphaNumFails(): void
    {
        $this->assertParity(['code' => 'alpha_num'], ['code' => 'Hello-123']);
    }

    public function testAsciiPasses(): void
    {
        $this->assertParity(['text' => 'ascii'], ['text' => 'Hello World!']);
    }

    public function testAsciiFailsWithUnicode(): void
    {
        $this->assertParity(['text' => 'ascii'], ['text' => 'Héllo']);
    }

    public function testRegexPasses(): void
    {
        $this->assertParity(['code' => 'regex:/^[A-Z]{3}[0-9]{3}$/'], ['code' => 'ABC123']);
    }

    public function testRegexFails(): void
    {
        $this->assertParity(['code' => 'regex:/^[A-Z]{3}[0-9]{3}$/'], ['code' => 'AB123']);
    }

    public function testNotRegexPasses(): void
    {
        $this->assertParity(['code' => 'not_regex:/^[0-9]+$/'], ['code' => 'ABC123']);
    }

    public function testNotRegexFails(): void
    {
        $this->assertParity(['code' => 'not_regex:/^[0-9]+$/'], ['code' => '123456']);
    }

    public function testStartsWithPasses(): void
    {
        $this->assertParity(['sku' => 'starts_with:PRE-'], ['sku' => 'PRE-1234']);
    }

    public function testStartsWithFails(): void
    {
        $this->assertParity(['sku' => 'starts_with:PRE-'], ['sku' => 'XYZ-1234']);
    }

    public function testEndsWithPasses(): void
    {
        $this->assertParity(['file' => 'ends_with:.pdf,.doc'], ['file' => 'report.pdf']);
    }

    public function testEndsWithFails(): void
    {
        $this->assertParity(['file' => 'ends_with:.pdf,.doc'], ['file' => 'report.txt']);
    }

    public function testDoesntStartWithPasses(): void
    {
        $this->assertParity(['sku' => 'doesnt_start_with:TMP-'], ['sku' => 'PRE-1234']);
    }

    public function testDoesntStartWithFails(): void
    {
        $this->assertParity(['sku' => 'doesnt_start_with:TMP-'], ['sku' => 'TMP-1234']);
    }

    public function testDoesntEndWithPasses(): void
    {
        $this->assertParity(['file' => 'doesnt_end_with:.tmp'], ['file' => 'report.pdf']);
    }

    public function testDoesntEndWithFails(): void
    {
        $this->assertParity(['file' => 'doesnt_end_with:.tmp'], ['file' => 'report.tmp']);
    }

    public function testLowercasePasses(): void
    {
        $this->assertParity(['handle' => 'lowercase'], ['handle' => 'ada']);
    }

    public function testLowercaseFails(): void
    {
        $this->assertParity(['handle' => 'lowercase'], ['handle' => 'Ada']);
    }

    public function testUppercasePasses(): void
    {
        $this->assertParity(['code' => 'uppercase'], ['code' => 'ABC']);
    }

    public function testUppercaseFails(): void
    {
        $this->assertParity(['code' => 'uppercase'], ['code' => 'Abc']);
    }
}
