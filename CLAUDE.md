# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

`vi/validation` is a high-performance, standalone PHP validation library (PHP >= 8.1) with an optional Laravel
integration layer. It reimplements most of Laravel's validation rule set behind a compile-once/execute-many
engine, aiming for large speedups over `illuminate/validation` on bulk/streaming datasets while staying
Laravel-rule-string compatible.

## Commands

```bash
composer install                       # install dependencies

./vendor/bin/phpunit                   # run the full test suite
./vendor/bin/phpunit --filter TestName # run a single test method/class
./vendor/bin/phpunit tests/Unit/Rules/StringValidationRulesTest.php  # run one test file

./vendor/bin/phpstan analyse -c phpstan.neon   # static analysis (level 8, src/ only)
```

Test suite lives entirely in `tests/Unit` (registered in `phpunit.xml`). The `tests/*.php` scripts at the
top level (`benchmark.php`, `check_parity.php`, `correctness_verify.php`, `fuzz_mismatches.php`,
`performance_repro.php`, etc.) are standalone dev scripts run directly with `php tests/<script>.php`, not
part of the PHPUnit suite — use them when investigating performance regressions or Laravel-parity mismatches.

CI (`.github/workflows/ci.yml`) runs the matrix PHP 8.1–8.3 × Laravel 10/11, then PHPStan followed by PHPUnit
with coverage. Match that before assuming a change is safe.

## Architecture

### Compile → Execute pipeline

This is the core design and the thing to understand before touching anything:

1. **Build**: `Validator::schema()` returns a `Schema\SchemaBuilder`. Calling `->field('name')` lazily creates
   a `Schema\FieldDefinition`, on which rule methods (`->required()`, `->min()`, `->when()`, etc.) are chained.
2. **Compile**: `SchemaBuilder::compile()` turns the field definitions into an immutable
   `Execution\CompiledSchema` (a list of `Execution\CompiledField`, each holding resolved `Rules\RuleInterface`
   instances). This is the expensive step the library is designed to do once and reuse — compiled schemas can
   be cached via `Cache\ArraySchemaCache` / `Cache\FileSchemaCache`.
3. **Execute**: `SchemaValidator::validate(array $data)` runs the compiled schema against a row. It first
   checks for a **native precompiled closure** on disk (see below); otherwise falls back to
   `Execution\ValidatorEngine::validate()`, which walks fields in order, evaluates `sometimes`/`exclude*`/
   `nullable`/emptiness semantics, applies each rule, and collects errors via `Execution\ErrorCollector` into
   an `Execution\ValidationResult`.
4. **Native codegen (fastest path)**: `Compilation\NativeCompiler` can generate raw PHP closure source for a
   compiled schema (bypassing reflection/rule-object dispatch entirely). `Compilation\ValidatorCompiler`
   writes this to `<cache_path>/native/<hash>.php` keyed by a content hash of the rules array
   (`NativeCompiler::generateKey`); `SchemaValidator::validate()` `require`s and caches this closure in-process
   once found, via `Execution\NativeValidator`.

### Rule system

- Every rule implements `Rules\RuleInterface::validate(mixed $value, string $field, ValidationContext $context): ?array`
  — return `null` on success, or `['rule' => ..., 'params' => [...], 'message' => ?]` on failure.
- Rules are self-registering via a `#[RuleName(RuleId::X)]` PHP attribute (see `Rules\RuleName`, `Rules\RuleId`)
  read reflectively by `Rules\RuleRegistry::register()`. `registerBuiltInRules()` lists every built-in rule
  class — **new rules must be added to that list** or they won't resolve from string rule definitions.
  The registry also handles alias names and guards against name collisions.
- Rules needing external services implement a marker/capability interface instead of hardcoding a dependency:
  `Rules\NumericAwareInterface` (context-sensitive numeric comparisons for `min`/`max`/etc.),
  `Rules\DatabaseValidatorInterface` (`exists`/`unique`), `Rules\PasswordHasherInterface` (`current_password`).
  These are injected into `ValidatorEngine`/`ValidationContext`, not into the rule constructors.
- "Implicit" rules (`required*`, `accepted*`, `filled`, `present`, `prohibited*`) are hardcoded by class name
  in `ValidatorEngine::isImplicitRule()` — they must run even when the field value is empty/null. Any new
  rule with that semantic needs to be added there too.
- `Rules\ClosureRule` wraps a Laravel-style `function($attribute, $value, $fail)` closure as a `RuleInterface`,
  used both for user-supplied inline closures and internally by `when()` conditional rules
  (`Rules\ConditionalRule`).

### Laravel integration (`src/Laravel/`)

- `LaravelRuleParser` converts Laravel's pipe-delimited rule strings (`'required|email'`) or rule arrays into
  `RuleInterface` lists via the `RuleRegistry`, so the same rule classes serve both the native schema API and
  Laravel-style strings.
- `FastValidatorFactory` / `FastValidatorWrapper` / `Facades\FastValidator` provide a drop-in
  `Validator::make()`-compatible API (`FastValidator::make($data, $rules)->fails()/->validated()`, plus
  `->stream()`).
- `LaravelValidatorAdapter` implements Laravel's `Validator` contract so `vi/validation` can transparently
  replace `illuminate/validation` in **override mode** (`config/fast-validation.php` → `mode: 'override'`) vs.
  **parallel mode** (opt-in via the `FastValidator` facade, the default).
- `FastValidationServiceProvider` wires config, and `Octane\OctaneValidatorProvider` hooks Octane's
  `WorkerStarting`/`WorkerStopping`/`RequestReceived`/`RequestTerminated` events to the `Runtime\ValidatorPool`
  when `runtime.pooling` is enabled — needed because compiled schemas/native closures must be safely reused
  across requests in a long-lived worker without leaking per-request state.

### Runtime / worker pooling (`src/Runtime/`)

For Octane/Swoole/RoadRunner, `Runtime\StatelessValidator` + `Runtime\ValidatorPool` avoid rebuilding
validators per request. `Runtime\Workers\SwooleAdapter` / `RoadRunnerAdapter` are thin lifecycle-hook adapters
for wiring the same pool outside Laravel/Octane. `Runtime\ContextManager` isolates per-validation state
(`ValidationContext`) so pooled validator instances don't leak data between requests/workers.

### Messages & localization (`src/Messages/`)

`Messages\Translator` loads message catalogs from `resources/lang/{locale}/validation.php` (English and
Arabic ship built-in; type-aware keys like `min.string` vs `min.numeric` mirror Laravel's convention).
`Messages\MessageResolver` layers custom per-field/per-rule message overrides and custom attribute names on
top of the translator, and must be passed to **both** `ValidatorEngine` (used during rule execution) and
`SchemaValidator` (used on the native-compiled fast path) — passing it to only one silently breaks message
customization on the other path.

### Batch/streaming execution (`src/Execution/`)

`SchemaValidator` exposes `stream()`, `each()`, `failures()`, `firstFailure()`, `allValid()` as
memory-conscious alternatives to `validateMany()` (which materializes every `ValidationResult` — avoid it
above a few thousand rows). `Execution\ChunkedValidator` batches rows into fixed-size chunks
(`validateInChunks()`, `streamChunks()` yielding `BatchValidationResult`, `streamFailures()`,
`countFailures()`) for ETL-style bulk-insert workflows.

## Conventions

- `declare(strict_types=1)` and PSR-12 throughout `src/`.
- PHPStan level 8 on `src/` only (`phpstan.neon`); keep new code passing at that level — `treatPhpDocTypesAsCertain: false`.
- Rule classes are `final` and one-rule-per-file under `src/Rules/`, named `<Thing>Rule.php`.
