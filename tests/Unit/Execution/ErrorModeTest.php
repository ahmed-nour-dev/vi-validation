<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Execution;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vi\Validation\Compilation\NativeArtifactRepository;
use Vi\Validation\Compilation\ValidatorCompiler;
use Vi\Validation\Execution\CompiledSchema;
use Vi\Validation\Execution\ErrorMode;
use Vi\Validation\Execution\ValidationFailure;
use Vi\Validation\Execution\ValidatorEngine;
use Vi\Validation\Laravel\FastValidatorFactory;
use Vi\Validation\SchemaValidator;
use Vi\Validation\Validator;

final class ErrorModeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        NativeArtifactRepository::flushMemory();
        $this->dir = sys_get_temp_dir() . '/vi-validation-errmode-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        NativeArtifactRepository::flushMemory();
        if (is_dir($this->dir)) {
            (new ValidatorCompiler(null, false, $this->dir))->clearNative();
            @unlink($this->dir . '/native/.lock');
            @rmdir($this->dir . '/native');
            @rmdir($this->dir);
        }
    }

    private function schema(): CompiledSchema
    {
        return Validator::fromRules([
            'name' => 'required|string|min:5|alpha',
            'email' => 'required|email',
            'age' => 'required|integer|min:18',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function badRow(): array
    {
        return ['name' => '1', 'email' => 'x', 'age' => 3];
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function paths(): array
    {
        return ['engine' => [false], 'native' => [true]];
    }

    private function validator(bool $native, ErrorMode $mode, bool $failFast = false, int $maxErrors = 100): SchemaValidator
    {
        $engine = new ValidatorEngine(null, $failFast, $maxErrors, $mode);
        $compiler = $native ? new ValidatorCompiler(null, true, $this->dir) : null;
        $validator = new SchemaValidator($this->schema(), $engine, $compiler);
        $this->assertSame($native, $validator->warm());

        return $validator;
    }

    #[DataProvider('paths')]
    public function testAllModeKeepsEveryError(bool $native): void
    {
        $result = $this->validator($native, ErrorMode::All)->validate($this->badRow());

        $this->assertSame(['name' => 2, 'email' => 1, 'age' => 1], $result->errorCountsByField());
        $this->assertSame(4, $result->errorCount());
        $this->assertSame(['min', 'alpha'], array_column($result->errors()['name'], 'rule'));
    }

    #[DataProvider('paths')]
    public function testFirstPerFieldKeepsOneErrorPerField(bool $native): void
    {
        $result = $this->validator($native, ErrorMode::FirstPerField)->validate($this->badRow());

        $this->assertFalse($result->isValid());
        $this->assertSame(['name' => 1, 'email' => 1, 'age' => 1], $result->errorCountsByField());
        $this->assertSame('min', $result->errors()['name'][0]['rule']);
    }

    #[DataProvider('paths')]
    public function testFirstPerRowStopsAtTheFirstError(bool $native): void
    {
        $result = $this->validator($native, ErrorMode::FirstPerRow)->validate($this->badRow());

        $this->assertFalse($result->isValid());
        $this->assertSame(['name' => 1], $result->errorCountsByField());
        $this->assertSame(['name'], $result->failedFields());
    }

    #[DataProvider('paths')]
    public function testCountOnlyKeepsNoDetails(bool $native): void
    {
        $validator = $this->validator($native, ErrorMode::CountOnly);
        $result = $validator->validate($this->badRow());

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->isCountOnly());
        $this->assertSame([], $result->errors());
        $this->assertSame([], $result->messages());
        $this->assertSame(4, $result->errorCount());
        $this->assertSame(['name' => 2, 'email' => 1, 'age' => 1], $result->errorCountsByField());
        $this->assertSame(['name', 'email', 'age'], $result->failedFields());

        $valid = $validator->validate(['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30]);
        $this->assertTrue($valid->isValid());
        $this->assertSame(0, $valid->errorCount());
    }

    #[DataProvider('paths')]
    public function testMaxErrorsAndFailFastAreHonouredOnBothPaths(bool $native): void
    {
        $this->assertSame(2, $this->validator($native, ErrorMode::All, false, 2)->validate($this->badRow())->errorCount());
        $this->assertSame(1, $this->validator($native, ErrorMode::All, true)->validate($this->badRow())->errorCount());
        $this->assertSame(2, $this->validator($native, ErrorMode::FirstPerField, false, 2)->validate($this->badRow())->errorCount());
    }

    public function testNativeAndEngineProduceIdenticalResultsInEveryMode(): void
    {
        $rows = [
            $this->badRow(),
            ['name' => 'Alice', 'email' => 'bad', 'age' => 30],
            ['name' => 'Al', 'age' => 'x'],
            [],
            ['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30],
        ];

        foreach (ErrorMode::cases() as $mode) {
            foreach ([[false, 100], [true, 100], [false, 1], [false, 3]] as [$failFast, $max]) {
                $engine = $this->validator(false, $mode, $failFast, $max);
                $native = $this->validator(true, $mode, $failFast, $max);

                foreach ($rows as $row) {
                    $e = $engine->validate($row);
                    $n = $native->validate($row);
                    $label = "{$mode->value} failFast=" . var_export($failFast, true) . " max={$max} " . json_encode($row);
                    $this->assertSame($e->isValid(), $n->isValid(), $label);
                    $this->assertSame($e->errors(), $n->errors(), $label);
                    $this->assertSame($e->errorCountsByField(), $n->errorCountsByField(), $label);
                }
            }
        }
    }

    public function testValidityNeverDependsOnTheMode(): void
    {
        $rows = [$this->badRow(), ['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30], ['email' => 'a@example.com']];

        foreach ($rows as $row) {
            $expected = $this->validator(false, ErrorMode::All)->validate($row)->isValid();
            foreach (ErrorMode::cases() as $mode) {
                $this->assertSame($expected, $this->validator(false, $mode)->validate($row)->isValid(), $mode->value);
            }
        }
    }

    public function testReportAggregatesAllRowsButStoresABoundedSample(): void
    {
        $rows = (static function () {
            for ($i = 0; $i < 1000; $i++) {
                yield "line-{$i}" => $i % 4 === 0
                    ? ['name' => 'x', 'email' => 'bad', 'age' => 30]
                    : ['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30];
            }
        })();

        $sink = [];
        $report = $this->validator(false, ErrorMode::FirstPerField)->report($rows, 10, static function (ValidationFailure $f) use (&$sink): void {
            $sink[] = $f->key;
        });

        $this->assertSame(1000, $report->rowsProcessed);
        $this->assertSame(250, $report->failedRows);
        $this->assertSame(750, $report->passedRows());
        $this->assertSame(500, $report->errorCount);
        $this->assertSame(['name' => 250, 'email' => 250], $report->errorCountsByField);
        $this->assertCount(10, $report->failures);
        $this->assertTrue($report->isTruncated());
        $this->assertFalse($report->allValid());
        $this->assertSame('line-4', $report->failures[1]->key);
        $this->assertSame(4, $report->failures[1]->index);
        $this->assertCount(250, $sink, 'the sink sees every failure, not just the stored sample');
        $this->assertSame(250, $report->summary()['failed_rows']);
    }

    public function testCountOnlyReportForPureStatistics(): void
    {
        $rows = array_fill(0, 100, $this->badRow());
        $report = $this->validator(true, ErrorMode::CountOnly)->report($rows, 0);

        $this->assertSame(100, $report->failedRows);
        $this->assertSame(400, $report->errorCount);
        $this->assertSame(['name' => 200, 'email' => 100, 'age' => 100], $report->errorCountsByField);
        $this->assertSame([], $report->failures);
    }

    public function testFactoryConfigAndWrapperSetter(): void
    {
        $factory = new FastValidatorFactory(['performance' => ['error_mode' => 'first_per_row']]);
        $wrapper = $factory->make($this->badRow(), ['name' => 'required|string|min:5', 'email' => 'required|email']);

        $this->assertSame(['name'], array_keys($wrapper->errors()->toArray()));

        $wrapper->setErrorMode(ErrorMode::All);
        $this->assertSame(['name', 'email'], array_keys($wrapper->errors()->toArray()));

        $this->assertSame(1, $wrapper->report([$this->badRow(), ['name' => 'Alice', 'email' => 'a@example.com']])->failedRows);
    }
}
