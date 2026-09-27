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

### Fixed
- All fluent-built schemas shared a single native artifact key (their raw rules array is
  empty), so one schema's native closure could be used for another (#19).
- `FastValidatorFactory`'s schema cache keyed closures and rule objects by
  `spl_object_id()`, which PHP reuses, so a later call could get a schema built with a
  different closure. Such rule sets are no longer cached (#19).

### Deprecated
- `NativeCompiler::generateKey()`; use `ValidatorCompiler::nativeKeyFor()` (#19).

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
