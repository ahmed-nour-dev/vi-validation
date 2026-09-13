<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Benchmarks;

/**
 * Turns a list of per-trial durations (seconds) into a summary that reports spread instead of
 * a single noisy number — the min/max/stddev matter as much as the median when judging whether
 * a benchmark run is trustworthy.
 */
final class Stats
{
    /**
     * @param list<float> $samples
     * @return array{min: float, max: float, mean: float, median: float, stddev: float, samples: int}
     */
    public static function summarize(array $samples): array
    {
        $count = count($samples);
        if ($count === 0) {
            return ['min' => 0.0, 'max' => 0.0, 'mean' => 0.0, 'median' => 0.0, 'stddev' => 0.0, 'samples' => 0];
        }

        $sorted = $samples;
        sort($sorted);

        $mean = array_sum($sorted) / $count;

        $mid = intdiv($count, 2);
        $median = $count % 2 === 0
            ? ($sorted[$mid - 1] + $sorted[$mid]) / 2
            : $sorted[$mid];

        $variance = 0.0;
        foreach ($sorted as $sample) {
            $variance += ($sample - $mean) ** 2;
        }
        $variance /= $count;

        return [
            'min' => $sorted[0],
            'max' => $sorted[$count - 1],
            'mean' => $mean,
            'median' => $median,
            'stddev' => sqrt($variance),
            'samples' => $count,
        ];
    }
}
