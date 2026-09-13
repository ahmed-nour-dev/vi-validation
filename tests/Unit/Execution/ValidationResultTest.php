<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Vi\Validation\Execution\ValidationResult;
use Vi\Validation\Validator;

/**
 * Regression coverage for ValidationResult::validated() correctly removing excluded
 * fields at any depth, including wildcard segments, without mutating data().
 *
 * @see https://github.com/ahmed-nour-dev/vi-validation/issues/7
 */
class ValidationResultTest extends TestCase
{
    /**
     * @param array<string, mixed> $data
     * @param list<string> $excludedFields
     */
    private function makeResult(array $data, array $excludedFields): ValidationResult
    {
        return new ValidationResult([], $data, null, $excludedFields);
    }

    public function testDepth1ExclusionIsRemoved(): void
    {
        $result = $this->makeResult(['name' => 'Ada', 'legacy' => 'x'], ['legacy']);

        self::assertSame(['name' => 'Ada'], $result->validated());
    }

    public function testNestedExclusionRemovesOnlyTheTargetLeaf(): void
    {
        $data = [
            'profile' => [
                'email' => 'ada@example.com',
                'name' => 'Ada',
            ],
        ];

        $result = $this->makeResult($data, ['profile.email']);

        self::assertSame(['profile' => ['name' => 'Ada']], $result->validated());
    }

    public function testDeeplyNestedExclusionRemovesOnlyTheTargetLeaf(): void
    {
        $data = [
            'profile' => [
                'address' => [
                    'city' => 'Springfield',
                    'zip' => '00000',
                ],
            ],
        ];

        $result = $this->makeResult($data, ['profile.address.city']);

        self::assertSame(
            ['profile' => ['address' => ['zip' => '00000']]],
            $result->validated()
        );
    }

    public function testWildcardExclusionRemovesLeafFromEveryElement(): void
    {
        $data = [
            'items' => [
                ['sku' => 'ABC-1', 'qty' => 2],
                ['sku' => 'ABC-2', 'qty' => 5],
            ],
        ];

        $result = $this->makeResult($data, ['items.*.sku']);

        self::assertSame(
            [
                'items' => [
                    ['qty' => 2],
                    ['qty' => 5],
                ],
            ],
            $result->validated()
        );
    }

    public function testWildcardExclusionWithNumericStringKeysWorks(): void
    {
        $data = ['items' => ['0' => ['sku' => 'A'], '1' => ['sku' => 'B']]];

        $result = $this->makeResult($data, ['items.*.sku']);

        self::assertSame(['items' => [[], []]], $result->validated());
    }

    public function testMultipleExclusionsInOneResultAreAllApplied(): void
    {
        $data = [
            'profile' => ['email' => 'a@b.com', 'name' => 'Ada'],
            'legacy' => 'x',
            'items' => [['sku' => 'A', 'qty' => 1]],
        ];

        $result = $this->makeResult($data, ['legacy', 'profile.email', 'items.*.sku']);

        self::assertSame(
            [
                'profile' => ['name' => 'Ada'],
                'items' => [['qty' => 1]],
            ],
            $result->validated()
        );
    }

    public function testExcludingBothParentAndChildDoesNotError(): void
    {
        $data = ['profile' => ['email' => 'a@b.com', 'name' => 'Ada']];

        $result = $this->makeResult($data, ['profile', 'profile.email']);

        self::assertSame([], $result->validated());
    }

    public function testExcludingChildThenParentDoesNotError(): void
    {
        $data = ['profile' => ['email' => 'a@b.com', 'name' => 'Ada']];

        $result = $this->makeResult($data, ['profile.email', 'profile']);

        self::assertSame([], $result->validated());
    }

    public function testMissingPathIsSilentlyIgnored(): void
    {
        $data = ['name' => 'Ada'];

        $result = $this->makeResult($data, ['profile.email', 'nonexistent', 'items.*.sku']);

        self::assertSame(['name' => 'Ada'], $result->validated());
    }

    public function testNumericArrayIndexExclusionRemovesOnlyThatElementKey(): void
    {
        $data = ['items' => [['sku' => 'A'], ['sku' => 'B']]];

        $result = $this->makeResult($data, ['items.0.sku']);

        self::assertSame(['items' => [[], ['sku' => 'B']]], $result->validated());
    }

    public function testOriginalDataIsNeverMutated(): void
    {
        $data = [
            'profile' => ['email' => 'a@b.com', 'name' => 'Ada'],
            'items' => [['sku' => 'A']],
        ];

        $result = $this->makeResult($data, ['profile.email', 'items.*.sku']);
        $result->validated();

        self::assertSame($data, $result->data());
    }

    public function testValidatedIsConsistentAcrossEngineAndNativePaths(): void
    {
        $schema = Validator::schema()
            ->field('reason')->excludeIf('status', 'closed')->required()
            ->field('status')->required()->string()
            ->compile();

        $result = $schema->validate(['status' => 'closed', 'reason' => 'no longer needed']);

        self::assertTrue($result->isValid());
        self::assertSame(['status' => 'closed'], $result->validated());
        self::assertSame(
            ['status' => 'closed', 'reason' => 'no longer needed'],
            $result->data()
        );
    }
}
