<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Parity;

use Vi\Validation\Tests\Unit\Parity\Support\ParityTestCase;

/**
 * Covers RuleId: date_format, date_equals, after, after_or_equal, before, before_or_equal,
 * timezone.
 */
class DateParityTest extends ParityTestCase
{
    public function testDateFormatPasses(): void
    {
        $this->assertParity(['dob' => 'date_format:Y-m-d'], ['dob' => '2024-01-15']);
    }

    public function testDateFormatFails(): void
    {
        $this->assertParity(['dob' => 'date_format:Y-m-d'], ['dob' => '01/15/2024']);
    }

    public function testDateEqualsPasses(): void
    {
        $this->assertParity(['event' => 'date_equals:2024-06-01'], ['event' => '2024-06-01']);
    }

    public function testDateEqualsFails(): void
    {
        $this->assertParity(['event' => 'date_equals:2024-06-01'], ['event' => '2024-06-02']);
    }

    public function testAfterPasses(): void
    {
        $this->assertParity(['end' => 'after:2024-01-01'], ['end' => '2024-02-01']);
    }

    public function testAfterFails(): void
    {
        $this->assertParity(['end' => 'after:2024-01-01'], ['end' => '2023-12-31']);
    }

    public function testAfterWithFieldReference(): void
    {
        $this->assertParity(
            ['start' => 'date', 'end' => 'after:start'],
            ['start' => '2024-01-01', 'end' => '2024-01-02']
        );
    }

    public function testAfterOrEqualPasses(): void
    {
        $this->assertParity(['end' => 'after_or_equal:2024-01-01'], ['end' => '2024-01-01']);
    }

    public function testAfterOrEqualFails(): void
    {
        $this->assertParity(['end' => 'after_or_equal:2024-01-01'], ['end' => '2023-12-31']);
    }

    public function testBeforePasses(): void
    {
        $this->assertParity(['dob' => 'before:2024-01-01'], ['dob' => '2000-01-01']);
    }

    public function testBeforeFails(): void
    {
        $this->assertParity(['dob' => 'before:2024-01-01'], ['dob' => '2024-06-01']);
    }

    public function testBeforeOrEqualPasses(): void
    {
        $this->assertParity(['dob' => 'before_or_equal:2024-01-01'], ['dob' => '2024-01-01']);
    }

    public function testBeforeOrEqualFails(): void
    {
        $this->assertParity(['dob' => 'before_or_equal:2024-01-01'], ['dob' => '2024-01-02']);
    }

    public function testTimezonePasses(): void
    {
        $this->assertParity(['tz' => 'timezone'], ['tz' => 'Europe/London']);
    }

    public function testTimezoneFails(): void
    {
        $this->assertParity(['tz' => 'timezone'], ['tz' => 'Not/A_Timezone']);
    }
}
