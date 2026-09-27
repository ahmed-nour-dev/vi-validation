# Benchmarks

A reproducible benchmark suite for vi/validation, runnable from a clean checkout:

```bash
composer install
php benchmarks/run.php                  # full suite: 1k / 10k / 100k rows → benchmarks/results/latest.{json,md}
php benchmarks/regression.php           # quick CI regression gate (~20 s)
```

Options for `run.php`:

| Option | Default | Meaning |
| :--- | :--- | :--- |
| `--sizes=` | `1000,10000,100000` | Row counts (add `1000000` for a 1M-row run) |
| `--scenarios=` | all | `user_import`, `order_lines`, `api_payload` |
| `--paths=` | all | `laravel`, `factory`, `engine`, `native` |
| `--laravel-max=` | `10000` | Skip Laravel above this size. Its throughput is flat, so the largest measured rate is the reference for bigger sizes |
| `--output=` / `--markdown=` | `benchmarks/results/latest.*` | Where to write results (git-ignored) |

## What is measured

**Datasets** (`benchmarks/Suite.php`) are realistic workloads. They are generated deterministically from a fixed seed and streamed from generators, so every run validates exactly the same rows without holding them in memory. About 10% of the rows are invalid.

| Scenario | Shape |
| :--- | :--- |
| `user_import` | CSV-style user import: `required\|string\|min\|max`, `email`, `nullable\|integer\|min\|max`, `boolean`, `nullable\|url`, `alpha_dash` |
| `order_lines` | ETL order lines: integers, `alpha_dash` SKUs, `numeric` prices, `in:` currencies, `nullable\|date_format`, optional coupon |
| `api_payload` | Nested JSON API payload: dot paths up to 3 levels, `regex`, `in`, `required_if`, `digits_between`, `array\|max` |

**Paths** are the ways of validating each dataset:

| Path | What it is |
| :--- | :--- |
| `laravel` | `Illuminate\Validation\Factory::make($row, $rules)->passes()` per row: the baseline |
| `factory` | `FastValidatorFactory::make($row, $rules)->passes()` per row: the drop-in Laravel-style API, including per-call setup |
| `engine` | One `SchemaValidator` reused for every row (compile once, validate many) on `ValidatorEngine` |
| `native` | Same, running the generated native PHP closure. Skipped when the schema isn't natively compilable |

**Metrics**, per scenario × path × size:

- total time, rows/s, and speedup over Laravel;
- the peak memory *added* while validating;
- the failure count, which must be identical across paths;
- per schema, the one-time costs, reported separately from throughput: schema compile time, native code generation + verification + atomic persist, and artifact load.

**Methodology**:
- Setup and one warm-up row happen before the clock starts, so steady state is measured with `hrtime()`.
- `gc_collect_cycles()` and `memory_reset_peak_usage()` (PHP ≥ 8.2) run before each measurement.
- The environment is recorded in the JSON: PHP and Laravel versions, compiler version, OS, CPU model and count, RAM, OPcache/JIT, SAPI, and timestamp.
- For CLI runs with OPcache/JIT, pass e.g. `php -d opcache.enable_cli=1 -d opcache.jit=tracing benchmarks/run.php`.

## Results

- [`results/reference.md`](results/reference.md) / [`results/reference.json`](results/reference.json) hold the committed reference run. The README's "Performance at a Glance" table is generated from it.
- To refresh them, run `php benchmarks/run.php --output=benchmarks/results/reference.json --markdown=benchmarks/results/reference.md` and update the README table from the new file.

## Regression gate

CI can't compare absolute timings: runners differ too much. `regression.php` therefore checks **ratios measured in the same process**, taking the best of 3 repetitions at 2,000 rows, against [`baseline.json`](baseline.json):

- each vi/validation path's speedup over Laravel's validator (`min_speedup_vs_laravel`);
- native over engine, for natively compilable scenarios (`min_native_over_engine`);
- peak memory added while validating (`max_peak_memory_bytes`), which catches accidental per-row retention.

It exits non-zero and lists every regression, and writes its measurements to `results/regression.json` (uploaded as a CI artifact). The thresholds sit well below the reference numbers, so noise doesn't fail builds. Raise them when a real speedup lands.
