<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Parity;

use Vi\Validation\Tests\Unit\Parity\Support\ParityTestCase;

/**
 * Covers RuleId: in, not_in, gt, gte, lt, lte, confirmed, same, different.
 */
class ComparisonParityTest extends ParityTestCase
{
    public function testInPasses(): void
    {
        $this->assertParity(['role' => 'in:admin,editor,viewer'], ['role' => 'editor']);
    }

    public function testInFails(): void
    {
        $this->assertParity(['role' => 'in:admin,editor,viewer'], ['role' => 'superuser']);
    }

    public function testNotInPasses(): void
    {
        $this->assertParity(['username' => 'not_in:admin,root'], ['username' => 'ada']);
    }

    public function testNotInFails(): void
    {
        $this->assertParity(['username' => 'not_in:admin,root'], ['username' => 'admin']);
    }

    public function testGtPasses(): void
    {
        $this->assertParity(
            ['max_qty' => 'integer', 'min_qty' => 'integer|gt:max_qty'],
            ['max_qty' => 5, 'min_qty' => 10]
        );
    }

    public function testGtFails(): void
    {
        $this->assertParity(
            ['max_qty' => 'integer', 'min_qty' => 'integer|gt:max_qty'],
            ['max_qty' => 10, 'min_qty' => 5]
        );
    }

    public function testGtePasses(): void
    {
        $this->assertParity(
            ['floor' => 'integer', 'value' => 'integer|gte:floor'],
            ['floor' => 5, 'value' => 5]
        );
    }

    public function testLtPasses(): void
    {
        $this->assertParity(
            ['ceiling' => 'integer', 'value' => 'integer|lt:ceiling'],
            ['ceiling' => 10, 'value' => 5]
        );
    }

    public function testLtFails(): void
    {
        $this->assertParity(
            ['ceiling' => 'integer', 'value' => 'integer|lt:ceiling'],
            ['ceiling' => 10, 'value' => 10]
        );
    }

    public function testLtePasses(): void
    {
        $this->assertParity(
            ['ceiling' => 'integer', 'value' => 'integer|lte:ceiling'],
            ['ceiling' => 10, 'value' => 10]
        );
    }

    public function testConfirmedPasses(): void
    {
        $this->assertParity(
            ['password' => 'confirmed'],
            ['password' => 'secret', 'password_confirmation' => 'secret']
        );
    }

    public function testConfirmedFails(): void
    {
        $this->assertParity(
            ['password' => 'confirmed'],
            ['password' => 'secret', 'password_confirmation' => 'different']
        );
    }

    public function testConfirmedFailsWhenConfirmationMissing(): void
    {
        $this->assertParity(['password' => 'confirmed'], ['password' => 'secret']);
    }

    public function testSamePasses(): void
    {
        $this->assertParity(
            ['password' => 'string', 'password_repeat' => 'same:password'],
            ['password' => 'secret', 'password_repeat' => 'secret']
        );
    }

    public function testSameFails(): void
    {
        $this->assertParity(
            ['password' => 'string', 'password_repeat' => 'same:password'],
            ['password' => 'secret', 'password_repeat' => 'other']
        );
    }

    public function testDifferentPasses(): void
    {
        $this->assertParity(
            ['old_password' => 'string', 'new_password' => 'different:old_password'],
            ['old_password' => 'secret', 'new_password' => 'newsecret']
        );
    }

    public function testDifferentFails(): void
    {
        $this->assertParity(
            ['old_password' => 'string', 'new_password' => 'different:old_password'],
            ['old_password' => 'secret', 'new_password' => 'secret']
        );
    }
}
