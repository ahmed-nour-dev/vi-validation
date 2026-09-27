<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Execution;

use Generator;
use PHPUnit\Framework\TestCase;
use Vi\Validation\Execution\ValidationFailure;
use Vi\Validation\Laravel\FastValidatorFactory;
use Vi\Validation\SchemaValidator;
use Vi\Validation\Validator;

final class FirstFailureWithIndexTest extends TestCase
{
    private function validator(): SchemaValidator
    {
        return new SchemaValidator(
            Validator::schema()
                ->field('email')->required()->email()
                ->compile()
        );
    }

    public function testReturnsNullWhenAllRowsPass(): void
    {
        $rows = [['email' => 'a@example.com'], ['email' => 'b@example.com']];

        $this->assertNull($this->validator()->firstFailureWithIndex($rows));
    }

    public function testReturnsNullForEmptyInput(): void
    {
        $this->assertNull($this->validator()->firstFailureWithIndex([]));
    }

    public function testReportsZeroBasedIndexOfFirstFailingRow(): void
    {
        $rows = [
            ['email' => 'a@example.com'],
            ['email' => 'b@example.com'],
            ['email' => 'not-an-email'],
            ['email' => 'also-bad'],
        ];

        $failure = $this->validator()->firstFailureWithIndex($rows);

        $this->assertInstanceOf(ValidationFailure::class, $failure);
        $this->assertSame(2, $failure->index);
        $this->assertSame(2, $failure->key);
        $this->assertFalse($failure->result->isValid());
        $this->assertArrayHasKey('email', $failure->errors());
        $this->assertArrayHasKey('email', $failure->messages());
    }

    public function testFirstRowFailingReportsIndexZero(): void
    {
        $failure = $this->validator()->firstFailureWithIndex([['email' => 'bad']]);

        $this->assertNotNull($failure);
        $this->assertSame(0, $failure->index);
        $this->assertSame(0, $failure->key);
    }

    public function testPreservesNonSequentialArrayKeys(): void
    {
        $rows = [
            10 => ['email' => 'a@example.com'],
            25 => ['email' => 'bad'],
            'row-x' => ['email' => 'bad'],
        ];

        $failure = $this->validator()->firstFailureWithIndex($rows);

        $this->assertNotNull($failure);
        $this->assertSame(1, $failure->index, 'index is the position in iteration order');
        $this->assertSame(25, $failure->key, 'key is the key the source produced');
    }

    public function testPreservesStringKeys(): void
    {
        $rows = ['alice' => ['email' => 'a@example.com'], 'bob' => ['email' => 'nope']];

        $failure = $this->validator()->firstFailureWithIndex($rows);

        $this->assertNotNull($failure);
        $this->assertSame(1, $failure->index);
        $this->assertSame('bob', $failure->key);
    }

    public function testPreservesGeneratorKeysAndStopsConsumingAfterFailure(): void
    {
        $consumed = 0;
        $rows = (function () use (&$consumed): Generator {
            // e.g. a CSV reader keyed by file line number (header on line 1)
            foreach ([2 => 'a@example.com', 3 => 'bad', 4 => 'c@example.com'] as $line => $email) {
                $consumed++;
                yield $line => ['email' => $email];
            }
        })();

        $failure = $this->validator()->firstFailureWithIndex($rows);

        $this->assertNotNull($failure);
        $this->assertSame(1, $failure->index);
        $this->assertSame(3, $failure->key);
        $this->assertSame(2, $consumed, 'rows after the first failure must not be read');
    }

    public function testKeylessGeneratorKeyEqualsIndex(): void
    {
        $rows = (function (): Generator {
            yield ['email' => 'a@example.com'];
            yield ['email' => 'bad'];
        })();

        $failure = $this->validator()->firstFailureWithIndex($rows);

        $this->assertNotNull($failure);
        $this->assertSame(1, $failure->index);
        $this->assertSame(1, $failure->key);
    }

    public function testFirstFailureStillReturnsPlainResult(): void
    {
        $rows = [['email' => 'a@example.com'], ['email' => 'bad']];

        $result = $this->validator()->firstFailure($rows);

        $this->assertNotNull($result);
        $this->assertFalse($result->isValid());
        $this->assertTrue($this->validator()->allValid([['email' => 'a@example.com']]));
        $this->assertFalse($this->validator()->allValid($rows));
    }

    public function testFastValidatorWrapperExposesIndexedFailure(): void
    {
        $wrapper = (new FastValidatorFactory())->make([], ['email' => 'required|email']);

        $failure = $wrapper->firstFailureWithIndex([
            5 => ['email' => 'a@example.com'],
            9 => ['email' => 'bad'],
        ]);

        $this->assertNotNull($failure);
        $this->assertSame(1, $failure->index);
        $this->assertSame(9, $failure->key);
    }
}
