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
