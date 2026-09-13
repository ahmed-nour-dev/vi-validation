<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Parity;

use Vi\Validation\Tests\Unit\Parity\Support\ParityTestCase;

/**
 * Covers RuleId: required_if, required_unless, required_with, required_with_all,
 * required_without, required_without_all, required_if_accepted, missing_if, missing_unless,
 * missing_with, missing_with_all, prohibited, prohibited_if, prohibited_unless, prohibits,
 * exclude, exclude_if, exclude_unless, exclude_with, exclude_without, accepted_if, declined_if.
 *
 * accepted_if/declined_if/exclude_if/exclude_unless/missing_if/missing_unless: LaravelRuleParser
 * only wires the first dependent value (see resources/compatibility-matrix.json, status
 * "partial") — Laravel's multi-value form (`accepted_if:field,v1,v2`) is intentionally not
 * covered here since the underlying rule classes don't support it yet.
 */
class ConditionalParityTest extends ParityTestCase
{
    public function testRequiredIfFailsWhenTriggered(): void
    {
        $this->assertParity(
            ['state' => 'required_if:country,US'],
            ['country' => 'US']
        );
    }

    public function testRequiredIfPassesWhenNotTriggered(): void
    {
        $this->assertParity(
            ['state' => 'required_if:country,US'],
            ['country' => 'FR']
        );
    }

    public function testRequiredUnlessPassesWhenExcepted(): void
    {
        $this->assertParity(
            ['state' => 'required_unless:country,FR'],
            ['country' => 'FR']
        );
    }

    public function testRequiredUnlessFailsOtherwise(): void
    {
        $this->assertParity(
            ['state' => 'required_unless:country,FR'],
            ['country' => 'US']
        );
    }

    public function testRequiredWithFailsWhenSiblingPresent(): void
    {
        $this->assertParity(
            ['last_name' => 'required_with:first_name'],
            ['first_name' => 'Ada']
        );
    }

    public function testRequiredWithAllRequiresEverySibling(): void
    {
        $this->assertParity(
            ['total' => 'required_with_all:price,quantity'],
            ['price' => 10]
        );
    }

    public function testRequiredWithoutFailsWhenSiblingAbsent(): void
    {
        $this->assertParity(
            ['email' => 'required_without:phone'],
            []
        );
    }

    public function testRequiredWithoutAllRequiresAllSiblingsAbsent(): void
    {
        $this->assertParity(
            ['email' => 'required_without_all:phone,fax'],
            ['fax' => '123']
        );
    }

    public function testRequiredIfAcceptedFailsWhenOtherAccepted(): void
    {
        $this->assertParity(
            ['reason' => 'required_if_accepted:terms'],
            ['terms' => 'yes']
        );
    }

    public function testRequiredIfAcceptedPassesWhenOtherNotAccepted(): void
    {
        $this->assertParity(
            ['reason' => 'required_if_accepted:terms'],
            ['terms' => 'no']
        );
    }

    public function testMissingIfPassesWhenAbsentAndTriggered(): void
    {
        $this->assertParity(
            ['ssn' => 'missing_if:country,US'],
            ['country' => 'US']
        );
    }

    public function testMissingIfFailsWhenPresentAndTriggered(): void
    {
        $this->assertParity(
            ['ssn' => 'missing_if:country,US'],
            ['country' => 'US', 'ssn' => '123-45-6789']
        );
    }

    public function testMissingUnlessFailsWhenPresentAndNotExcepted(): void
    {
        $this->assertParity(
            ['ssn' => 'missing_unless:country,US'],
            ['country' => 'FR', 'ssn' => '123-45-6789']
        );
    }

    public function testMissingWithFailsWhenBothPresent(): void
    {
        $this->assertParity(
            ['discount_code' => 'missing_with:gift_card'],
            ['gift_card' => 'ABC', 'discount_code' => 'XYZ']
        );
    }

    public function testMissingWithAllFailsWhenEverySiblingPresent(): void
    {
        $this->assertParity(
            ['note' => 'missing_with_all:a,b'],
            ['a' => 1, 'b' => 2, 'note' => 'oops']
        );
    }

    public function testProhibitedFailsWhenPresent(): void
    {
        $this->assertParity(['legacy_field' => 'prohibited'], ['legacy_field' => 'x']);
    }

    public function testProhibitedIfFailsWhenTriggered(): void
    {
        $this->assertParity(
            ['other_reason' => 'prohibited_if:reason,other'],
            ['reason' => 'other', 'other_reason' => 'x']
        );
    }

    public function testProhibitedUnlessFailsWhenNotExcepted(): void
    {
        $this->assertParity(
            ['override' => 'prohibited_unless:role,admin'],
            ['role' => 'user', 'override' => 'x']
        );
    }

    public function testProhibitsFailsWhenBothPresent(): void
    {
        $this->assertParity(
            ['consultation' => 'prohibits:emergency'],
            ['consultation' => 'x', 'emergency' => 'y']
        );
    }

    public function testExcludeAlwaysDropsFieldWithoutError(): void
    {
        $this->assertParity(['legacy' => 'exclude|required'], ['legacy' => null]);
    }

    public function testExcludeIfExcludesWhenTriggered(): void
    {
        $this->assertParity(
            ['reason' => 'exclude_if:status,closed|required'],
            ['status' => 'closed']
        );
    }

    public function testExcludeUnlessExcludesUnlessMatching(): void
    {
        $this->assertParity(
            ['reason' => 'exclude_unless:status,open|required'],
            ['status' => 'closed']
        );
    }

    public function testExcludeWithExcludesWhenSiblingPresent(): void
    {
        $this->assertParity(
            ['legacy' => 'exclude_with:modern|required'],
            ['modern' => 'x']
        );
    }

    public function testExcludeWithoutExcludesWhenSiblingAbsent(): void
    {
        $this->assertParity(
            ['legacy' => 'exclude_without:modern|required'],
            []
        );
    }

    public function testAcceptedIfFailsWhenTriggeredAndNotAccepted(): void
    {
        $this->assertParity(
            ['newsletter' => 'accepted_if:marketing,yes'],
            ['marketing' => 'yes', 'newsletter' => 'no']
        );
    }

    public function testAcceptedIfPassesWhenNotTriggered(): void
    {
        $this->assertParity(
            ['newsletter' => 'accepted_if:marketing,yes'],
            ['marketing' => 'no']
        );
    }

    public function testDeclinedIfFailsWhenTriggeredAndAccepted(): void
    {
        $this->assertParity(
            ['optout' => 'declined_if:marketing,no'],
            ['marketing' => 'no', 'optout' => 'yes']
        );
    }
}
