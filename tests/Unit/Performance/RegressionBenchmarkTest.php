<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Performance;

use PHPUnit\Framework\Attributes\Group;
use Vi\Validation\Tests\Benchmarks\Runner;
use Vi\Validation\Tests\Benchmarks\ScenarioRegistry;
use Vi\Validation\Tests\Benchmarks\Stats;
use Vi\Validation\Tests\Unit\Parity\Support\ParityTestCase;

/**
 * Lightweight, CI-safe performance regression gate (issue #9).
 *
 * Shared GitHub Actions runners have noisy, variable CPU speed, so an absolute wall-clock
 * threshold would be flaky. Instead this measures the Laravel validator and vi/validation's
 * native-compiled path back-to-back, in the same process, on the same small dataset, so both
 * feel identical runner noise - and asserts only that the *ratio* between them hasn't
 * collapsed. The floor (3x) is deliberately far below the ~17-34x normally observed (see
 * README.md "Performance at a Glance") to avoid failing the build on ordinary noise while
 * still catching a real regression: an accidental fallback to the slow path, broken native
 * codegen, or an O(n^2) change in the hot loop.
 *
 * A speed "win" that's actually a correctness bug (e.g. validation silently no-oping) would
 * defeat the point of this gate, so it first asserts Laravel/vi parity on a representative
 * sample of rows via the same ParityTestCase harness the rest of the suite relies on.
 */
#[Group('performance')]
final class RegressionBenchmarkTest extends ParityTestCase
{
    private const ROW_COUNT = 2000;
    private const TRIALS = 7;
    private const WARMUP = 3;
    private const MINIMUM_SPEEDUP = 3.0;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeTempDir($dir);
        }
        $this->tempDirs = [];

        parent::tearDown();
    }

    public function testNativeCompiledValidationMaintainsMinimumSpeedupOverLaravel(): void
    {
        $scenario = ScenarioRegistry::get('medium');

        // Correctness guard: a handful of representative rows (valid and invalid) must agree
        // between Laravel and vi/validation before the ratio below is allowed to mean anything
        // - otherwise a "faster" build that silently stopped validating could pass this gate.
        foreach ([0, 1, 2, 10, 20] as $index) {
            $this->assertParity($scenario->rules, ($scenario->rowGenerator)($index));
        }

        $rows = $scenario->rows(self::ROW_COUNT);
        $runner = new Runner();

        $laravel = $runner->measureLaravel($scenario->rules, $rows, self::TRIALS, self::WARMUP);

        $cacheDir = sys_get_temp_dir() . '/vi-validation-perf-gate-' . bin2hex(random_bytes(8));
        $this->tempDirs[] = $cacheDir;
        $native = $runner->measureNative($scenario->rules, $rows, self::TRIALS, self::WARMUP, $cacheDir);

        self::assertTrue(
            $native['supported'],
            "The 'medium' scenario must be native-compilable for this gate to be meaningful."
        );

        $laravelMedian = Stats::summarize($laravel['durations'])['median'];
        $nativeMedian = Stats::summarize($native['durations'])['median'];

        self::assertGreaterThan(0.0, $nativeMedian, 'Native validation reported zero duration - timing is broken.');

        $speedup = $laravelMedian / $nativeMedian;

        self::assertGreaterThanOrEqual(
            self::MINIMUM_SPEEDUP,
            $speedup,
            sprintf(
                'vi/validation native speedup over Laravel dropped to %.1fx (floor: %.1fx). '
                . 'Laravel median: %.4fs, native median: %.4fs over %d rows.',
                $speedup,
                self::MINIMUM_SPEEDUP,
                $laravelMedian,
                $nativeMedian,
                self::ROW_COUNT
            )
        );
    }

    private function removeTempDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeTempDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
