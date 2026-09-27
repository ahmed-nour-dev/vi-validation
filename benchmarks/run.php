<?php

/**
 * Full benchmark suite. See benchmarks/README.md.
 *
 *   php benchmarks/run.php                                   # 1k, 10k, 100k rows
 *   php benchmarks/run.php --sizes=1000,10000,100000,1000000 --laravel-max=10000
 *   php benchmarks/run.php --scenarios=user_import --paths=engine,native --output=my.json
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED); // Laravel's own deprecations on PHP 8.4+

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Suite.php';

use Vi\Validation\Benchmarks\Suite;

$options = getopt('', ['sizes::', 'scenarios::', 'paths::', 'laravel-max::', 'output::', 'markdown::']);
$sizes = array_map('intval', explode(',', (string) ($options['sizes'] ?? '1000,10000,100000')));
$scenarios = explode(',', (string) ($options['scenarios'] ?? implode(',', Suite::SCENARIOS)));
$paths = explode(',', (string) ($options['paths'] ?? implode(',', Suite::PATHS)));
$laravelMax = (int) ($options['laravel-max'] ?? 10000);
$output = (string) ($options['output'] ?? __DIR__ . '/results/latest.json');
$markdown = (string) ($options['markdown'] ?? __DIR__ . '/results/latest.md');

$suite = new Suite();
$report = ['environment' => Suite::environment(), 'one_time' => [], 'results' => []];

fprintf(STDERR, "PHP %s, Laravel validation %s, OPcache %s, JIT %s\n",
    $report['environment']['php'], $report['environment']['laravel_validation'],
    $report['environment']['opcache'] ? 'on' : 'off', $report['environment']['jit'] ? 'on' : 'off');

foreach ($scenarios as $scenario) {
    $report['one_time'][$scenario] = $suite->oneTimeCosts($scenario);
    $laravelRate = null;

    foreach ($sizes as $size) {
        foreach ($paths as $path) {
            if ($path === 'laravel' && $size > $laravelMax) {
                continue; // too slow to be worth it; throughput is flat, the largest size measured is the reference
            }

            fprintf(STDERR, "  %-12s %-8s %9s rows ... ", $scenario, $path, number_format($size));
            $result = $suite->run($scenario, $path, $size);
            if ($result === null) {
                fprintf(STDERR, "n/a (not natively compilable)\n");
                continue;
            }
            if ($path === 'laravel') {
                $laravelRate = $result['rows_per_sec'];
            }
            $result['speedup_vs_laravel'] = $laravelRate !== null && $path !== 'laravel'
                ? round($result['rows_per_sec'] / $laravelRate, 1)
                : null;
            fprintf(STDERR, "%s rows/s\n", number_format($result['rows_per_sec']));

            $report['results'][] = ['scenario' => $scenario, 'path' => $path] + $result;
        }
    }
}

@mkdir(dirname($output), 0755, true);
file_put_contents($output, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
file_put_contents($markdown, render($report));
fprintf(STDERR, "\nWrote %s and %s\n", $output, $markdown);

/**
 * @param array<string, mixed> $report
 */
function render(array $report): string
{
    $env = $report['environment'];
    $out = [
        '# vi/validation benchmark results',
        '',
        sprintf('PHP %s · illuminate/validation %s · %s · %s (%s CPUs) · OPcache %s · JIT %s · %s',
            $env['php'], $env['laravel_validation'], $env['os'], $env['cpu'] ?? 'unknown CPU', $env['cpu_count'] ?? '?',
            $env['opcache'] ? 'on' : 'off', $env['jit'] ? 'on' : 'off', $env['timestamp']),
        '',
        '## One-time costs (per schema)',
        '',
        '| Scenario | Compile schema | Native codegen + persist | Artifact load | Native? |',
        '| :--- | ---: | ---: | ---: | :---: |',
    ];
    foreach ($report['one_time'] as $scenario => $c) {
        $out[] = sprintf('| %s | %.3f ms | %s | %s | %s |', $scenario, $c['compile_ms'],
            $c['codegen_ms'] !== null ? sprintf('%.3f ms', $c['codegen_ms']) : '—',
            $c['load_ms'] !== null ? sprintf('%.3f ms', $c['load_ms']) : '—',
            $c['native_compilable'] ? 'yes' : 'no');
    }
    $out[] = '';
    $out[] = '## Steady-state throughput';
    $out[] = '';
    $out[] = '| Scenario | Path | Rows | Time | Rows/s | vs Laravel | Peak memory added |';
    $out[] = '| :--- | :--- | ---: | ---: | ---: | ---: | ---: |';
    foreach ($report['results'] as $r) {
        $out[] = sprintf('| %s | %s | %s | %.3f s | %s | %s | %s KB |', $r['scenario'], $r['path'], number_format($r['rows']),
            $r['seconds'], number_format($r['rows_per_sec']),
            $r['speedup_vs_laravel'] !== null ? $r['speedup_vs_laravel'] . '×' : '—',
            number_format($r['peak_memory_bytes'] / 1024, 1));
    }

    return implode("\n", $out) . "\n";
}
