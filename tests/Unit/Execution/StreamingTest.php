<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Execution;

use Generator;
use Illuminate\Support\LazyCollection;
use PHPUnit\Framework\TestCase;
use Vi\Validation\Compilation\NativeArtifactRepository;
use Vi\Validation\Compilation\ValidatorCompiler;
use Vi\Validation\Execution\ChunkedValidator;
use Vi\Validation\Execution\ValidationResult;
use Vi\Validation\Laravel\FastValidatorFactory;
use Vi\Validation\SchemaValidator;
use Vi\Validation\Validator;

final class StreamingTest extends TestCase
{
    private ?string $dir = null;

    protected function tearDown(): void
    {
        NativeArtifactRepository::flushMemory();
        if ($this->dir !== null && is_dir($this->dir)) {
            (new ValidatorCompiler(null, false, $this->dir))->clearNative();
            @unlink($this->dir . '/native/.lock');
            @rmdir($this->dir . '/native');
            @rmdir($this->dir);
        }
    }

    private function validator(bool $native = false): SchemaValidator
    {
        $schema = Validator::fromRules([
            'id' => 'required|integer|min:1',
            'email' => 'required|email',
            'name' => 'nullable|string|max:50',
        ]);

        if (!$native) {
            return new SchemaValidator($schema);
        }

        $this->dir = sys_get_temp_dir() . '/vi-validation-stream-' . bin2hex(random_bytes(6));
        $validator = new SchemaValidator($schema, null, new ValidatorCompiler(null, true, $this->dir));
        $this->assertTrue($validator->warm());

        return $validator;
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private static function rows(int $count, int &$consumed = 0): Generator
    {
        for ($i = 1; $i <= $count; $i++) {
            $consumed++;
            yield [
                'id' => $i,
                'email' => $i % 10 === 0 ? 'broken' : "user{$i}@example.com",
                'name' => "User {$i}",
            ];
        }
    }

    public function testRowsArePulledLazilyOneAtATime(): void
    {
        $consumed = 0;
        $stream = $this->validator()->stream(self::rows(1000, $consumed));

        $this->assertSame(0, $consumed, 'nothing is read before iteration starts');

        foreach ($stream as $index => $result) {
            $this->assertSame($index + 1, $consumed, 'exactly one row is read per yielded result');
            if ($index === 4) {
                break;
            }
        }

        $this->assertSame(5, $consumed, 'no read-ahead / materialization');
    }

    public function testPreserveKeysUsesSourceKeys(): void
    {
        $rows = (static function (): Generator {
            yield 'line-2' => ['id' => 1, 'email' => 'a@example.com'];
            yield 'line-3' => ['id' => 2, 'email' => 'nope'];
            yield 'line-4' => ['id' => 0, 'email' => 'b@example.com'];
        });
        $validator = $this->validator();

        $this->assertSame([0, 1, 2], array_keys(iterator_to_array($validator->stream($rows()))));
        $this->assertSame(['line-2', 'line-3', 'line-4'], array_keys(iterator_to_array($validator->stream($rows(), true))));
        $this->assertSame([1, 2], array_keys(iterator_to_array($validator->failures($rows()))));
        $this->assertSame(['line-3', 'line-4'], array_keys(iterator_to_array($validator->failures($rows(), true))));

        $seen = [];
        $validator->each($rows(), static function (ValidationResult $r, int|string $key) use (&$seen): void {
            $seen[] = $key;
        }, true);
        $this->assertSame(['line-2', 'line-3', 'line-4'], $seen);

        $chunked = new ChunkedValidator($validator);
        $this->assertSame(['line-3', 'line-4'], array_keys(iterator_to_array($chunked->streamFailures($rows(), 1000, true))));
    }

    public function testLazyCollectionIsStreamedWithoutMaterialization(): void
    {
        $consumed = 0;
        $lazy = LazyCollection::make(static function () use (&$consumed): Generator {
            yield from self::rows(100, $consumed);
        });

        $failures = 0;
        foreach ($this->validator()->failures($lazy) as $index => $result) {
            $failures++;
            $this->assertSame($index + 1, $consumed);
        }

        $this->assertSame(10, $failures);
        $this->assertSame(100, $consumed);
    }

    public function testFastValidatorWrapperStreamsGeneratorsLazily(): void
    {
        $consumed = 0;
        $wrapper = (new FastValidatorFactory())->make([], ['id' => 'required|integer', 'email' => 'required|email']);

        foreach ($wrapper->stream(self::rows(50, $consumed)) as $index => $result) {
            if ($index === 9) {
                break;
            }
        }

        $this->assertSame(10, $consumed);
    }

    public function testChunkedFailureHelpersDoNotBuffer(): void
    {
        $consumed = 0;
        $chunked = new ChunkedValidator($this->validator());

        foreach ($chunked->streamFailures(self::rows(1000, $consumed), 500) as $index => $result) {
            $this->assertSame(9, $index);
            break;
        }

        $this->assertSame(10, $consumed, 'first failure is yielded as soon as it is read, not after a 500-row chunk');
        $this->assertSame(100, $chunked->countFailures(self::rows(1000)));
    }

    public function testEarlyTerminationLeavesValidatorReusable(): void
    {
        $validator = $this->validator();

        foreach ($validator->stream(self::rows(100)) as $index => $result) {
            if ($index === 9) {
                $this->assertFalse($result->isValid());
                break;
            }
        }

        $this->assertTrue($validator->validate(['id' => 5, 'email' => 'ok@example.com'])->isValid());
        $this->assertSame(['email'], array_keys($validator->validate(['id' => 5, 'email' => 'x'])->errors()));
        $this->assertSame(10, iterator_count($validator->failures(self::rows(100))));
    }

    public function testExceptionFromTheSourceMidStreamLeavesValidatorReusable(): void
    {
        $validator = $this->validator();
        $rows = (static function (): Generator {
            yield ['id' => 1, 'email' => 'broken'];
            throw new \RuntimeException('cursor lost');
        })();

        try {
            foreach ($validator->stream($rows) as $result) {
                $this->assertFalse($result->isValid());
            }
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('cursor lost', $e->getMessage());
        }

        $this->assertTrue($validator->validate(['id' => 1, 'email' => 'a@example.com'])->isValid());
    }

    public function testYieldedResultsAreIndependentOfLaterRows(): void
    {
        $results = iterator_to_array($this->validator()->stream([
            ['id' => 1, 'email' => 'bad'],
            ['id' => 2, 'email' => 'good@example.com'],
            ['id' => 'x', 'email' => 'good@example.com'],
        ]));

        $this->assertSame(['email'], array_keys($results[0]->errors()));
        $this->assertTrue($results[1]->isValid());
        $this->assertSame(['id'], array_keys($results[2]->errors()));
        $this->assertSame(['id' => 1, 'email' => 'bad'], $results[0]->data());
    }

    public function testMemoryStaysBoundedRegardlessOfRowCount(): void
    {
        foreach ([false, true] as $native) {
            $validator = $this->validator($native);

            $checkpoint = null;
            $growth = 0;
            foreach ($validator->stream(self::rows(200_000)) as $index => $result) {
                if ($index === 20_000) {
                    gc_collect_cycles();
                    $checkpoint = memory_get_usage();
                }
            }
            gc_collect_cycles();
            $growth = memory_get_usage() - (int) $checkpoint;

            // 180k further rows must not grow memory: it is bounded by one row + one result.
            $this->assertLessThan(256 * 1024, $growth, ($native ? 'native' : 'engine') . " grew by {$growth} bytes");
        }
    }
}
