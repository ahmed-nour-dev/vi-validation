<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Parity;

use Vi\Validation\Tests\Unit\Parity\Support\ParityTestCase;

/**
 * Covers RuleId: accepted, declined.
 */
class AcceptanceParityTest extends ParityTestCase
{
    public function testAcceptedPassesWithYes(): void
    {
        $this->assertParity(['terms' => 'accepted'], ['terms' => 'yes']);
    }

    public function testAcceptedPassesWithTrue(): void
    {
        $this->assertParity(['terms' => 'accepted'], ['terms' => true]);
    }

    public function testAcceptedPassesWithOne(): void
    {
        $this->assertParity(['terms' => 'accepted'], ['terms' => '1']);
    }

    public function testAcceptedFailsWithNo(): void
    {
        $this->assertParity(['terms' => 'accepted'], ['terms' => 'no']);
    }

    public function testAcceptedFailsWhenMissing(): void
    {
        $this->assertParity(['terms' => 'accepted'], []);
    }

    public function testDeclinedPassesWithNo(): void
    {
        $this->assertParity(['optout' => 'declined'], ['optout' => 'no']);
    }

    public function testDeclinedPassesWithFalse(): void
    {
        $this->assertParity(['optout' => 'declined'], ['optout' => false]);
    }

    public function testDeclinedFailsWithYes(): void
    {
        $this->assertParity(['optout' => 'declined'], ['optout' => 'yes']);
    }

    public function testDeclinedFailsWhenMissing(): void
    {
        $this->assertParity(['optout' => 'declined'], []);
    }
}
