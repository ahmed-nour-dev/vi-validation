# Native compilation & schema artifacts

This document describes how vi/validation identifies compiled schemas and how the
generated native PHP artifacts are keyed. See the README's "Native compilation
compatibility contract" for which rules can be inlined.

## Schema identity (fingerprints)

Every `CompiledSchema` has a deterministic fingerprint:

```php
$fingerprint = $schema->fingerprint();   // Vi\Validation\Compilation\SchemaFingerprint

$fingerprint->schemaHash;       // sha256 of the schema's validation semantics
$fingerprint->artifactKey;      // sha256 of schemaHash + format/compiler/PHP versions
$fingerprint->stable;           // false if the identity can't be captured (closures, ...)
$fingerprint->unstableReasons;  // e.g. ['avatar: closure']
```

It is computed once per schema object and memoized.

### What goes into `schemaHash`

The fingerprint is computed from the **compiled** schema, never from the input format:

- the ordered list of field names;
- each field's execution flags: `nullable`, `bail`, `sometimes`, `exclude` and the
  conditional `exclude_*` rules;
- every resolved rule, in execution order, encoded as its class name plus the value of
  every property (including inherited private ones), recursively for nested objects.

Values are encoded canonically and type-tagged (`"10"`, `10` and `10.0` are all different;
strings are length-prefixed), so no two different rule states can encode the same way.

Consequences:

- `'required|email'`, `['required', 'email']` and `->required()->email()` fingerprint
  identically (modulo parameter types: the rule-string parser produces numeric parameters as
  floats, so `max:10` equals `->max(10.0)`, not `->max(10)` — int and float parameters are
  deliberately never assumed equivalent; at worst that costs a cache miss).
- Anything that changes whether a row passes or which rule fails — a parameter, the numeric
  context of `min`/`max`, rule order, a new rule, a renamed field — changes the fingerprint.
- Custom messages, custom attribute names and the locale are **not** part of the
  fingerprint: they only change how an error is rendered, which happens after validation.

### What goes into `artifactKey`

`artifactKey = sha256(schemaHash | SchemaFingerprint::FORMAT_VERSION | NativeCompiler::COMPILER_VERSION | PHP_VERSION_ID)`

It keys everything generated from a schema (native closures today). Upgrading PHP (including
a patch release) or the package's compiler produces a new key, so stale artifacts are never
looked up again.

### Unstable schemas

Some rules have no identity that can be captured deterministically: closures (inline rule
callbacks, `when()` conditions), resources, anonymous classes, non-serializable internal
objects and reference cycles. A schema containing one is fingerprinted with
`stable = false`, and its hashes are salted with a per-process random value plus the schema
object's id. So:

- an unstable fingerprint never equals another schema's fingerprint, in this process or any
  other;
- it must never be used to key persisted/shared artifacts.
  `ValidatorCompiler::writeNativeFor()` refuses to write one.

The same applies to the Laravel factory's compiled-schema cache: rule sets containing
closures, or rule objects that can't be serialized, are not cached and are rebuilt per call.
They used to be keyed by `spl_object_id()`, which PHP reuses once an object is freed, so a
later request could get back a schema built with a different closure.

### Migration note

`NativeCompiler::generateKey(array $rules)` is deprecated. It hashed the raw rules array,
which is empty for every fluent schema, so all fluent schemas used to share a single native
artifact key. Use `ValidatorCompiler::nativeKeyFor($schema)` (or
`$schema->fingerprint()->artifactKey`) and `ValidatorCompiler::writeNativeFor($schema)`.

## Artifact lifecycle & cache invalidation

Native artifacts live in `<cache_path>/native/<artifactKey>.php` and are managed by
`Compilation\NativeArtifactRepository` (reachable via `ValidatorCompiler::nativeRepository()`).

### Writing

`ValidatorCompiler::writeNativeFor($schema)` (or `NativeArtifactRepository::store()`):

1. refuses unstable schemas and schemas with a rule `NativeCompiler` can't inline;
2. takes an exclusive `flock()` on `<dir>/.lock`, so concurrent workers generating the same
   artifact on a cold cache serialize, and re-checks for a valid artifact after acquiring it
   (the losers of the race reuse the winner's file instead of regenerating it);
3. prepends a header recording the artifact format, the key, the compiler version,
   `PHP_VERSION_ID` and a SHA-256 of the body;
4. syntax-checks the complete file with `token_get_all(..., TOKEN_PARSE)` — this parses
   without executing anything — and refuses to persist invalid PHP;
5. writes it to a unique temporary file in the same directory and `rename()`s it into place,
   so readers only ever see no file or a complete file;
6. invalidates the OPcache entry for the path.

A failure at any step throws `NativeArtifactException` from the repository; `ValidatorCompiler`
swallows it and returns `null`, since failing to produce an artifact must never break
validation.

### Loading

`SchemaValidator` resolves its native artifact **once per instance** (not per row): the
first `validate()` call (or `usesNative()`) asks the repository for the schema's closure and
otherwise uses `ValidatorEngine` for the lifetime of the instance. There is no per-row
hashing or `file_exists()`, and no lookup at all when no `cache_path` is configured.

`NativeArtifactRepository::load()` only `require`s a file after checking its header (format
and key must match) and its body checksum, so a truncated, tampered or foreign file (for
example one copied under another key) is never executed. After `require` it checks that the
file returned a `Closure` whose result has the expected `valid`/`errors`/`excluded_fields`
shape. A file that fails any check is deleted and reported as absent: validation falls back
to the engine and the next `writeNativeFor()` regenerates it.

Verified closures are memoized per process (keyed by directory + key). In long-running
workers each artifact is read, hashed and required at most once per process.
`NativeArtifactRepository::flushMemory()` clears that memo.

### Invalidation

Because `artifactKey` includes the compiler version and `PHP_VERSION_ID`, upgrading either
makes old artifacts unreachable; nothing stale is ever loaded. To reclaim disk space:

```php
$compiler = new \Vi\Validation\Compilation\ValidatorCompiler(null, false, $cachePath);

$compiler->pruneNative();   // delete artifacts for other compiler/PHP versions + corrupt files
$compiler->clearNative();   // delete every native artifact
```

`pruneNative()` is safe to run on every deploy. `clearPrecompiled()` also clears native
artifacts.
