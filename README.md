# vi/validation 🚀

> **The High-Performance PHP Validation Library**

[![Latest Version on Packagist](https://img.shields.io/packagist/v/vi/validation.svg?style=flat-square)](https://packagist.org/packages/vi/validation)
[![Total Downloads](https://img.shields.io/packagist/dt/vi/validation.svg?style=flat-square)](https://packagist.org/packages/vi/validation)

**vi/validation** is a blazing fast, memory-efficient validation library designed for high-performance applications. Whether you are processing large datasets, building high-frequency APIs, or running long-lived processes (Octane, Swoole), this library handles it with minimal overhead.

---

## ⚡ Performance at a Glance

Stop trading performance for convenience. **vi/validation** delivers **17x to 34x speedups** compared to standard Laravel validation.

| Scenario | Rows | FastValidator 🚀 | Laravel Validator | Speedup | Throughput |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Complex Rules** | 100,000 | **1.48s** | 48.83s | **33.0x** | ~67,713 req/s |
| **Complex Rules** | 10,000 | **0.14s** | 4.83s | **34.1x** | ~70,696 req/s |
| **Medium Rules** | 100,000 | **1.36s** | 39.11s | **28.7x** | ~73,365 req/s |
| **Simple Rules** | 100,000 | **1.14s** | 19.66s | **17.2x** | ~87,739 req/s |

> *Benchmarks run on PHP 8.2.29. "Complex Rules" include nested fields, regex, conditional requirements, and type checks.*

---

## 🌟 Why vi/validation?

- **Compile Once, Validate Many**: Schemas are compiled into optimized execution plans, eliminating repetitive parsing overhead.
- **Native Code Generation**: Automatically generates raw PHP code for your validation rules, bypassing reflection and dynamic calls entirely.
- **O(1) Memory Usage**: Stream validated data from massive datasets (CSVs, JSON streams) without ever loading everything into RAM.
- **Laravel Compatible**: Drop-in support for Laravel rules. Use it alongside standard validators or replace them entirely.
- **Octane Ready**: First-class support for Laravel Octane, Swoole, and RoadRunner with stateless validation and worker pools.

---

## 📚 Table of Contents

- [Installation](#-installation)
- [Quick Start](#-quick-start)
- [Key Features & Documentation](#-key-features--documentation)
  - [Streaming & Large Datasets](#-streaming--large-datasets)
  - [Chunked & Batch Validation](#-chunked--batch-validation)
  - [Working with Validation Results](#-working-with-validation-results)
  - [Custom Validation Rules (Closures)](#-custom-validation-rules-closures)
  - [Conditional Field Rules](#-conditional-field-rules)
  - [Supported Rules](#-supported-rules)
  - [Localization & Custom Messages](#-localization--custom-messages)
  - [Long-Running Processes (Octane, Swoole, RoadRunner)](#-long-running-processes-octane-swoole-roadrunner)
- [Configuration](#-configuration)
- [Testing](#-testing)
- [License](#-license)

---

## 📦 Installation

```bash
composer require vi/validation
```

---

## 🚀 Quick Start

### 1. Standalone PHP

Ideal for scripts, ETL jobs, or non-Laravel projects.

```php
use Vi\Validation\Validator;
use Vi\Validation\SchemaValidator;

// 1. Define & Compile Schema
$schema = Validator::schema()
    ->field('email')->required()->email()
    ->field('age')->required()->integer()->min(18)
    ->compile();

// 2. Create Validator
$validator = new SchemaValidator($schema);

// 3. Validate
$data = ['email' => 'user@example.com', 'age' => 25];
$result = $validator->validate($data);

if ($result->isValid()) {
    // success
} else {
    print_r($result->messages());
}
```

### 2. Laravel (Parallel Mode) - *Recommended*

Use `vi/validation` explicitly where you need performance, keeping standard Laravel validation elsewhere.

```php
use Vi\Validation\Laravel\Facades\FastValidator;

public function store()
{
    // Use the Facade just like Validator::make()
    $validator = FastValidator::make(request()->all(), [
        'email' => 'required|email',
        'name'  => 'required|string|max:100',
    ]);

    if ($validator->fails()) {
        return back()->withErrors($validator)->withInput();
    }

    $validated = $validator->validated();
    // ...
}
```

### 3. Laravel (Override Mode)

Transparently route standard `Validator::make()` calls through the fast engine.

1. Publish config: `php artisan vendor:publish --tag=config --provider="Vi\Validation\Laravel\FastValidationServiceProvider"`
2. Edit `config/fast-validation.php`:
   ```php
   'mode' => 'override',
   ```
3. Use Laravel validation as usual. Supported rules will be accelerated automatically.

### 4. Streaming with Laravel Facade

Process large datasets memory-efficiently using the Laravel-style API. The `FastValidator::make()` method accepts iterables (generators, iterators) and provides a `stream()` method.

```php
use Vi\Validation\Laravel\Facades\FastValidator;

// Assume $inputs is a Generator or large array
$validator = FastValidator::make($inputs, $rules);

$validCount = 0;
$invalidCount = 0;
$totalErrors = 0;

// Stream results one by one - O(1) Memory Usage
foreach ($validator->stream() as $index => $result) {
    if ($result->isValid()) {
        $validCount++;
        // Process valid row...
    } else {
        $invalidCount++;
        $totalErrors += count($result->errors());
        // Log errors...
    }
}
```

---

## 📖 Key Features & Documentation

### 🌊 Streaming & Large Datasets

Validating 100,000 rows? Don't crash your server. Use `stream()` to process records one by one with constant memory usage.

```php
$schema = Validator::schema()
    ->field('id')->required()->integer()
    ->compile();

$validator = new SchemaValidator($schema);

// Zero memory spikes, even with 1M+ rows
foreach ($validator->stream($largeDataset) as $result) {
    if (!$result->isValid()) {
        // Log error
    }
}
```

`SchemaValidator` also exposes a few other memory-conscious helpers built on top of `stream()`:

```php
// Run a callback per row without ever storing results
$validator->each($rows, function ($result, int $index) {
    if (!$result->isValid()) {
        Log::error("Row {$index} failed", $result->errors());
    }
});

// Yield only the rows that failed
foreach ($validator->failures($rows) as $index => $result) {
    // ...
}

// Stop at the very first failure (fail-fast)
$firstFailure = $validator->firstFailure($rows);

// Cheap boolean check over an entire dataset
$allGood = $validator->allValid($rows);
```

> ⚠️ `validateMany()` materializes every result in memory — reach for it only on small datasets. Prefer `stream()`, `each()`, or `failures()` for anything with more than a few thousand rows.

### 📦 Chunked & Batch Validation

For ETL jobs and imports, `ChunkedValidator` processes rows in fixed-size batches so you can, for example, bulk-insert valid rows into a database chunk-by-chunk:

```php
use Vi\Validation\Execution\ChunkedValidator;

$chunked = new ChunkedValidator($validator);

// Run a callback for every chunk of results
$chunked->validateInChunks($rows, chunkSize: 500, onChunk: function (int $chunkIndex, array $results) {
    // $results is a list<ValidationResult> for this chunk
});

// Or stream BatchValidationResult objects (Countable + IteratorAggregate)
foreach ($chunked->streamChunks($rows, chunkSize: 500) as $chunkIndex => $batch) {
    echo "Chunk {$chunkIndex}: {$batch->failureCount()} failed of {$batch->count()}\n";

    foreach ($batch->failures() as $result) {
        // handle each failed row
    }
}

// Yield only failing rows (with their original index) across the whole dataset
foreach ($chunked->streamFailures($rows, chunkSize: 1000) as $index => $result) {
    // ...
}

// Count failures without keeping any results in memory
$totalFailures = $chunked->countFailures($rows, chunkSize: 1000);
```

### ✅ Working with Validation Results

Every validation call returns a `ValidationResult` with a small, focused API:

| Method | Description |
| :--- | :--- |
| `isValid(): bool` | Whether the data passed all rules. |
| `data(): array` | The raw input data that was validated. |
| `validated(): array` | The input data minus any fields excluded via `exclude*` rules. |
| `errors(): array` | Raw, per-field error entries (`rule`, `params`, `message`). |
| `messages(): array` | Formatted, human-readable messages grouped by field. |
| `allMessages(): array` | A flat list of every message across all fields. |
| `firstMessage(string $field): ?string` | The first message for a specific field. |
| `first(): ?string` | The first message across the whole result. |

```php
$result = $validator->validate($data);

if ($result->isValid()) {
    $safeData = $result->validated();
} else {
    return response()->json(['errors' => $result->messages()], 422);
}

echo $result; // "Validation passed." or a newline-joined list of messages
```

### 🎯 Custom Validation Rules (Closures)

Drop in one-off rules using a Laravel-style closure — no need to write a dedicated rule class:

```php
use Vi\Validation\Rules\ClosureRule;

$schema = Validator::schema()
    ->field('username')
        ->required()
        ->rules(new ClosureRule(function (string $attribute, mixed $value, \Closure $fail) {
            if (str_contains((string) $value, ' ')) {
                $fail("The {$attribute} must not contain spaces.");
            }
        }))
    ->compile();
```

### 🔀 Conditional Field Rules

Use `when()` to layer extra rules onto a field depending on another field's value — either a static boolean (evaluated immediately) or a callable (evaluated per-row at validation time):

```php
$schema = Validator::schema()
    ->field('country')->required()->string()
    ->field('postal_code')
        ->required()->string()
        ->when(
            fn (array $data) => ($data['country'] ?? null) === 'US',
            onTrue: fn ($field) => $field->regex('/^\d{5}(-\d{4})?$/'),
        )
    ->compile();
```

> Note: rules added inside `when()` are skipped when the field's own value is empty/null (like most non-`required*` rules), so `when()` is for layering extra constraints onto a field that already has a base rule such as `required()` — it's not a way to make a field conditionally required. For that, use [`requiredIf()` / `requiredUnless()`](#-supported-rules) instead.

### 🛠 Supported Rules

We support a comprehensive set of almost all standard Laravel rules.

| Category | Rules |
| :--- | :--- |
| **Core & Presence** | `required`, `nullable`, `filled`, `present`, `missing`, `bail`, `sometimes` |
| **Conditionals** | `required_if`, `required_unless`, `required_with`, `required_with_all`, `required_without`, `required_without_all`, `required_if_accepted`, `missing_if`, `missing_unless`, `missing_with`, `missing_with_all`, `prohibited`, `prohibited_if`, `prohibited_unless`, `prohibits`, `exclude`, `exclude_if`, `exclude_unless`, `exclude_with`, `exclude_without` |
| **Types** | `string`, `integer`, `numeric`, `boolean`, `array`, `list`, `date`, `json`, `enum`, `decimal` |
| **Strings** | `email`, `url`, `active_url`, `ip`, `ipv4`, `ipv6`, `mac_address`, `uuid`, `ulid`, `alpha`, `alpha_dash`, `alpha_num`, `ascii`, `regex`, `not_regex`, `starts_with`, `ends_with`, `doesnt_start_with`, `doesnt_end_with`, `lowercase`, `uppercase` |
| **Numbers & Size** | `min`, `max`, `size`, `between`, `digits`, `digits_between`, `multiple_of` |
| **Comparison** | `in`, `not_in`, `gt`, `gte`, `lt`, `lte`, `confirmed`, `same`, `different` |
| **Dates** | `date_format`, `date_equals`, `after`, `after_or_equal`, `before`, `before_or_equal`, `timezone` |
| **Arrays** | `distinct`, `required_array_keys` |
| **Files** | `file`, `image`, `mimes`, `mimetypes`, `min_file_size`, `max_file_size`, `dimensions` |
| **Acceptance** | `accepted`, `accepted_if`, `declined`, `declined_if` |
| **Database** | `exists`, `unique` |
| **Auth** | `password`, `current_password` |
| **Others** | `country`, `language` |

### 🌐 Localization & Custom Messages

Fully localized error messages. English and Arabic are built-in (`resources/lang/{en,ar}/validation.php`), including type-aware variants (e.g. `min.string` vs `min.numeric`).

```php
use Vi\Validation\Messages\Translator;

$translator = new Translator('ar'); // Switch to Arabic

// Point to your own lang directory to add locales or override messages
$translator = new Translator('fr', __DIR__ . '/resources/lang');

// Add/override messages at runtime
$translator->addMessages(['required' => 'Le champ :attribute est requis.'], 'fr');
```

To override messages per-field/per-rule and customize attribute names, wrap the translator in a `MessageResolver` and pass it into `SchemaValidator`:

```php
use Vi\Validation\SchemaValidator;
use Vi\Validation\Execution\ValidatorEngine;
use Vi\Validation\Messages\MessageResolver;
use Vi\Validation\Messages\Translator;

$resolver = new MessageResolver(new Translator('en'));

$resolver->setCustomMessages([
    'email.required' => 'Please provide your email address.', // field.rule
    'unique' => 'This value has already been taken.',          // rule-wide
]);

$resolver->setCustomAttributes([
    'email' => 'email address',
]);

// The resolver must be given to the engine (used during validation) as well
// as to SchemaValidator (used for the precompiled/native validator path).
$validator = new SchemaValidator($schema, new ValidatorEngine($resolver), messageResolver: $resolver);
```

In Laravel, `FastValidator::make()` accepts the same `$messages` and `$customAttributes` arguments as `Validator::make()`.

### ⚡ Long-Running Processes (Octane, Swoole, RoadRunner)

For worker-based runtimes where the process stays alive across requests, `vi/validation` avoids re-creating validators on every request:

- **`Vi\Validation\Runtime\ValidatorPool`** — a pool of reusable `StatelessValidator` instances. Enable it with `runtime.pooling` in the config (see below); `OctaneValidatorProvider` wires it into Octane's `WorkerStarting`/`WorkerStopping`/`RequestReceived`/`RequestTerminated` events automatically.
- **`Vi\Validation\Runtime\Workers\SwooleAdapter`** and **`RoadRunnerAdapter`** — thin adapters for wiring the same lifecycle hooks into Swoole and RoadRunner workers directly.

```php
use Vi\Validation\Runtime\ValidatorPool;

$pool = new ValidatorPool(maxSize: 10);
$pool->onWorkerStart(); // pre-warm on worker boot

// Automatic acquire/release around a unit of work
$result = $pool->withValidator(function ($validator) use ($schema, $data) {
    return $validator->validate($schema, $data); // StatelessValidator::validate(CompiledSchema, array)
});

$pool->onWorkerStop(); // drain on worker shutdown
```

---

## ⚙️ Configuration

Publish the config file in a Laravel app:

```bash
php artisan vendor:publish --tag=config --provider="Vi\Validation\Laravel\FastValidationServiceProvider"
```

This creates `config/fast-validation.php` with the following options (each overridable via an environment variable):

| Key | Env Variable | Default | Description |
| :--- | :--- | :--- | :--- |
| `mode` | — | `parallel` | `parallel` (opt-in `FastValidator` facade) or `override` (route `Validator::make()` through the fast engine). |
| `cache.enabled` | `FAST_VALIDATION_CACHE` | `true` | Cache compiled schemas to avoid recompiling on every request. |
| `cache.driver` | `FAST_VALIDATION_CACHE_DRIVER` | `array` | `array` (per-request) or `file` (persisted across requests/workers). |
| `cache.ttl` | `FAST_VALIDATION_CACHE_TTL` | `3600` | Cache lifetime in seconds. |
| `cache.path` | — | `storage/framework/validation/cache` | Storage path used by the `file` cache driver. |
| `compilation.precompile` | `FAST_VALIDATION_PRECOMPILE` | `false` | Generate and persist native PHP validator closures ahead of time for maximum throughput in production. |
| `compilation.cache_path` | — | `storage/framework/validation/compiled` | Where precompiled native validators are stored. |
| `performance.fail_fast` | `FAST_VALIDATION_FAIL_FAST` | `false` | Stop validating a field after its first error. |
| `performance.max_errors` | `FAST_VALIDATION_MAX_ERRORS` | `100` | Stop collecting errors after this many, to bound worst-case cost on malformed input. |
| `performance.fast_path_rules` | `FAST_VALIDATION_FAST_PATH` | `true` | Enable optimized code paths for common rule combinations. |
| `localization.locale` | `FAST_VALIDATION_LOCALE` | `en` | Default locale for error messages. |
| `localization.fallback_locale` | `FAST_VALIDATION_FALLBACK_LOCALE` | `en` | Locale used when a message is missing in the active locale. |
| `runtime.pooling` | `FAST_VALIDATION_POOLING` | `false` | Enable `ValidatorPool` instance reuse for Octane/Swoole/RoadRunner. |
| `runtime.pool_size` | `FAST_VALIDATION_POOL_SIZE` | `10` | Maximum number of pooled validator instances. |
| `runtime.auto_detect` | `FAST_VALIDATION_AUTO_DETECT` | `true` | Auto-detect long-running environments and tune behavior accordingly. |

#### Native compilation compatibility contract

When `compilation.precompile` (or a `cache_path` passed to `ValidatorCompiler`) is enabled, `NativeCompiler` tries to turn a schema into a single inlined PHP closure for maximum throughput. **A native validator must never change validation semantics compared to the standard `ValidatorEngine`.**

Only a fixed set of rules can currently be inlined: `required`, `string`, `integer`, `numeric`, `boolean`, `array`, `email`, `url`, `ip`, `json`, `min`, `max`, `alpha`, `alpha_num`, and `alpha_dash`. If a schema contains any other rule (e.g. `in`, `regex`, `unique`, `distinct`, database rules, closures, ...), `NativeCompiler::compile()` throws `UnsupportedNativeRuleException` instead of silently dropping the rule. `ValidatorCompiler::writeNative()` catches this and simply does not write a native artifact for that schema — `SchemaValidator::validate()` then finds no cached native file and transparently falls back to `ValidatorEngine`, so the exact same schema always produces the exact same result whether or not it happened to be native-compilable.

You can check ahead of time whether a schema is fully native-compilable:

```php
$compiler = new \Vi\Validation\Compilation\NativeCompiler();

$compiler->canCompile($schema);          // bool
$compiler->findUnsupportedRules($schema); // e.g. ['status:Vi\Validation\Rules\InRule']
```

Outside Laravel, pass the same shape directly to `SchemaValidator::build()`:

```php
$validator = \Vi\Validation\SchemaValidator::build(
    definition: fn ($schema) => $schema->field('email')->required()->email(),
    config: [
        'compilation' => [
            'precompile' => true,
            'cache_path' => __DIR__ . '/storage/compiled',
        ],
    ],
);
```

---

## 🧪 Testing

Run the test suite to verify everything is working on your machine:

```bash
composer install
./vendor/bin/phpunit
```

---

## 📝 License

This project is open-sourced software licensed under the [MIT license](LICENSE).
