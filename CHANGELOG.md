# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Comprehensive gap analysis and roadmap document.
- Week 1 foundational tasks (CHANGELOG, LICENSE, CONTRIBUTING).
- Explicit PHP/Laravel compatibility matrix: CI now runs a `--prefer-lowest` dependency job
  and clearly-named Laravel-integration/native-compilation PHPUnit testsuites per matrix cell,
  and the supported combinations are documented in a new README "Compatibility" section.
- `SchemaValidator::firstFailureWithIndex()` (and `FastValidatorWrapper::firstFailureWithIndex()`)
  returning a `ValidationFailure` value object carrying the failing row's zero-based `index`, the
  source iterable's own `key`, and its `ValidationResult` (#12).
- Deterministic schema fingerprints: `CompiledSchema::fingerprint()` returns a
  `SchemaFingerprint` (`schemaHash`, `artifactKey`, `stable`) computed from the compiled
  schema rather than its input format. Native artifacts are now keyed by it via
  `ValidatorCompiler::nativeKeyFor()` / `writeNativeFor()` (#19).
- `NativeArtifactRepository` hardens the native artifact lifecycle: flock-serialized
  generation, syntax check before activation, atomic temp-file + rename writes, a
  versioned/checksummed header verified before `require`, shape verification of the returned
  closure, automatic discard of corrupt artifacts with safe engine fallback, per-process
  memoization of loaded closures, and `ValidatorCompiler::clearNative()` / `pruneNative()` (#15).
- `SchemaValidator::usesNative()` (#15).
- Optional HMAC signing of native artifacts, file-cached schemas and precompiled schemas
  (`security.signing_key`, defaulting to `APP_KEY` in Laravel); unverified files are never
  `require`d or `unserialize()`d. Artifacts that are symlinks, world-writable, or in a
  world-writable non-sticky directory are refused (#20).
- Documented security model & trust boundaries in `docs/native-compilation.md` (#20).
- Explicit schema lifecycle (build → compile → generate/persist → load → execute), documented
  in `docs/native-compilation.md`: `Validator::fromRules()`, `SchemaValidator::warm()`,
  `FastValidatorFactory::compile()` / `precompile()` / `clearCompiled()` / `pruneCompiled()` /
  `getCompiler()`, `FastValidatorWrapper::getSchemaValidator()`, `Laravel\RuleSetCompiler`,
  plus `tests/benchmark_lifecycle.php` separating one-time from steady-state costs (#16).
- `nullable` and `bail` are native-compilable, so most real-world schemas can now run natively
  (2.3x the engine's throughput in the lifecycle benchmark) (#16).
- Schema diagnostics: `SchemaValidator::diagnostics()`, `FastValidatorWrapper::diagnostics()`
  and `FastValidatorFactory::diagnose()` return a `SchemaDiagnostics` report (fingerprint,
  counts, per-field native support with reasons, artifact status/path, strategy, versions,
  compile time) that never includes rule parameters or data; plus
  `FastValidatorFactory::cacheStats()` and `NativeArtifactRepository::stats()` (#18).
- `preserveKeys` option for `stream()`, `failures()`, `each()` (on `SchemaValidator` and
  `FastValidatorWrapper`) and `ChunkedValidator::streamFailures()`, yielding the source's own
  keys; streaming guarantees documented; `tests/benchmark_streaming.php` publishes memory
  alongside throughput for 10k/100k/1M rows (#17).
- Configurable error collection for large imports: `ErrorMode` (`All`, `FirstPerField`,
  `FirstPerRow`, `CountOnly`) via `SchemaValidator::setErrorMode()`, the `ValidatorEngine`
  constructor or `performance.error_mode`; `SchemaValidator::report()` returning a
  `ValidationReport` (all rows counted, bounded failure sample, per-field counts, streaming
  `onFailure` sink); `ValidationResult::errorCount()`, `errorCountsByField()`,
  `failedFields()`, `isCountOnly()`; `tests/benchmark_error_modes.php` (#13).
- Seeded property-based / fuzz test suite (`tests/Unit/Property/`, group `property`,
  `VI_FUZZ_SEED` / `VI_FUZZ_ITERATIONS`) covering native-vs-engine equivalence, validator
  reuse, input immutability, `max_errors` bounds, streaming equivalence and "no exception
  unless Laravel throws", plus `FuzzRegressionTest` for every bug it found (#14).
- Complete Laravel rule coverage: `contains`, `hex_color`, `in_array`, `max_digits`,
  `min_digits`, `present_if`, `present_unless`, `present_with`, `present_with_all`,
  `prohibited_if_accepted`, `prohibited_if_declined` and `required_if_declined` (with
  English/Arabic messages), and multiple dependent values for `accepted_if`, `declined_if`,
  `exclude_if`, `exclude_unless`, `missing_if` and `missing_unless` (#11).
- Complete, enforced rule compatibility matrix: `resources/compatibility-matrix.json` gains
  `engine`, `dependencies` and `laravel_since` per rule and is rendered to `docs/rules.md`
  (`php tests/generate_rule_matrix.php`). CI checks that every registry rule and every rule
  the installed Laravel defines is listed, `engine` matches the registry, `native_compilable`
  matches `NativeCompilableInterface`, the README's Supported Rules table matches, and
  `docs/rules.md` is current (#11).
- Laravel rule objects (`Rule::in()`, `Rule::unique()`, custom `ValidationRule` /
  `InvokableRule` / legacy `Rule` classes) via `Laravel\LaravelRuleAdapter`;
  `FastValidatorFactory::setDatabaseValidator()` / `setPasswordHasher()` (#11).
- `sometimes` is a native-compilable marker, like `nullable`/`bail` (#11).
- Reproducible benchmark suite (`benchmarks/`): deterministic user-import / order-lines ETL /
  nested-API datasets at 1k-100k(+) rows comparing Laravel, `FastValidator::make()`, the
  compiled engine and native execution; rows/s, peak memory, one-time compile/codegen/load
  costs and full environment metadata in JSON + Markdown. A CI performance job
  (`benchmarks/regression.php`) gates on same-run ratios against `benchmarks/baseline.json`.
  The README's performance table is now generated from `benchmarks/results/reference.*` (#9).

### Fixed
- All fluent-built schemas shared a single native artifact key (their raw rules array is
  empty), so one schema's native closure could be used for another (#19).
- `FastValidatorFactory`'s schema cache keyed closures and rule objects by
  `spl_object_id()`, which PHP reuses, so a later call could get a schema built with a
  different closure. Such rule sets are no longer cached (#19).
- `SchemaValidator::validate()` hashed the rules array and called `file_exists()` on every
  row (even with no cache path configured, probing `/native/<key>.php`); the native artifact
  is now resolved once per validator instance (#15).
- **Security:** `NativeCompiler` wrote raw field names into a `//` comment, so a field name
  containing a newline or `?>` injected executable PHP into the generated validator; field
  names are now only emitted as `var_export()`ed literals with index-based
  variables/labels. `addslashes()`-escaped keys also broke for names containing `"` or NUL
  bytes, silently validating the wrong key (#20).
- **Security:** unknown rule names and rules missing required parameters were silently
  dropped (so a typo disabled validation); they now throw like Laravel. In override mode
  such rule sets are handed to Laravel's validator, which also now receives the custom
  messages/attributes that were previously discarded (#20).
- `FileSchemaCache` entries stored with a TTL of 0 ("never expires") could never be read
  back; cache writes are now atomic (#20).
- The Laravel factory ignored the `compilation.*` config and `SchemaValidator::build()` with
  `precompile` never generated anything, so native validators were never used. Both now
  load existing artifacts from `cache_path` and generate on first use when `precompile` is on;
  the message resolver is passed to the native path too (#16).
- `FastValidatorWrapper::sometimes()` rebuilt the validator with default settings, dropping
  custom messages/attributes, fail-fast/max-errors and Laravel's numeric context for
  `min`/`max` (#16).
- Native validators omitted `params` from errors without parameters; error arrays now have
  the same shape as the engine's (#16).
- `ChunkedValidator::streamFailures()` buffered up to `$chunkSize` rows before yielding the
  first failure, and `countFailures()` materialized every chunk's results; both now stream
  row by row with no buffering (#17).
- Native validators ignored `fail_fast` / `max_errors` / `stopOnFirstFailure()` and always
  returned every error; the engine's error policy is now applied to native results, so both
  paths return identical errors (#13).
- Long-running worker state leakage (#10):
  - `ValidatorEngine` kept the last row's data (and a throwing rule's partial errors)
    reachable after `validate()`; row state is now cleared in a `finally`.
  - `ContextManager` left one request's custom messages/attributes active for the next
    request that didn't set any.
  - Pooled `StatelessValidator`s kept engine settings changed by a previous borrower
    (fail-fast, max errors, error mode, resolver, DB validator, hasher); they are restored on
    release. `StatelessValidator::validate()` no longer resets request state per row.
  - `ValidatorPool` could enqueue a validator twice on double release (sharing it between two
    borrowers) and retained validators by object id; it now tracks checkouts in `WeakMap`s,
    ignores double/foreign releases and exposes `getCheckedOutCount()`.
- The bundled Arabic catalog (and any `resources/lang/{locale}`) was never loaded unless a
  lang path was configured, so `localization.locale = 'ar'` produced English messages (#10).
- Bugs found by the property tests (#14):
  - **Blank strings:** Laravel treats strings that are empty after `trim()` as empty.
    `required`, `filled` and `required_*` accepted `"   "` / `"\t"`, `prohibited*` rejected
    them, and non-implicit rules (`email`, `integer`, `min`, ...) failed them instead of skipping.
  - Dot paths deeper than two segments (`a.b.c`) were looked up as `$data['a']['b.c']` by the
    engine (always missing) while the native path walked every segment; both now use Laravel
    dot notation, including conditional rules referencing such paths.
  - Natively compiled `sometimes` on nested fields checked for a literal `'p.q'` key.
  - `in` / `not_in` raised "Array to string conversion" for array values; they now follow
    Laravel (element-wise with an `array` rule on the field, `not_in` defined as `!in`).
  - Date rules threw `ValueError` for strings containing NUL bytes; `date` also accepted
    values `strtotime()` parses but that aren't calendar dates (e.g. `"x"`), unlike Laravel.
  - `distinct` raised "Array to string conversion" for arrays containing arrays.
- Laravel integration gaps (#11):
  - Laravel rule objects crashed the parser with a `TypeError`. `ValidationRule`,
    `InvokableRule` and legacy `Rule` objects now run through `Laravel\LaravelRuleAdapter`
    (with `DataAwareRule` and implicit-rule support), and Stringable rules (`Rule::in()`,
    `Rule::unique()`, `Rule::requiredIf()`, ...) are parsed from their string form.
  - Rule parameters now follow Laravel's grammar: CSV with quoting (`in:"a,b",c`), and
    `regex`/`not_regex` as a single parameter. Commas inside a regex (`{1,3}`) used to split it.
  - `ipv4`/`ipv6` lost their version restriction and behaved like `ip`.
  - **`exists`/`unique` always passed through `FastValidator::make()`**: no database validator
    was ever wired. `FastValidationServiceProvider` now backs them with Laravel's presence
    verifier (`PresenceVerifierDatabaseValidator`, including array values and connections), and
    without a database validator they throw instead of passing.
  - `current_password` passed whenever no hasher was configured. It is now backed by Laravel's
    auth + hasher (`AuthPasswordHasher`) and fails closed.
  - `required_if` / `required_unless` compared the other field strictly against the string
    parameters, so typed input never matched (e.g. `required_if:type,1` with `type: 1`, or
    `required_if:active,true` with `active: true`). All `*_if` / `*_unless` rules now use
    Laravel's dependent-value matching.

### Deprecated
- `NativeCompiler::generateKey()`; use `ValidatorCompiler::nativeKeyFor()` (#19).
- `ValidatorCompiler::writeNative($key, $schema)`; use `writeNativeFor($schema)` (#15).

## [0.1.0] - 2026-02-04

### Added
- High-performance PHP validation engine.
- Laravel integration with both 'parallel' and 'override' modes.
- Streaming validation for large datasets.
- Support for almost all standard Laravel rules.
- Native code generation for maximum speed.
- Localization support (English and Arabic).
- Schema caching (Array and File drivers).
- Worker pooling support for long-running environments (Octane, Swoole).
