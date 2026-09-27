# Laravel rule compatibility matrix

<!-- Generated from resources/compatibility-matrix.json by `php tests/generate_rule_matrix.php`. Do not edit by hand. -->

Every rule vi/validation knows about, and every rule the supported Laravel versions define.
**115 rules: 107 with verified Laravel parity, 20 natively compilable.**

Parity is verified against the real `illuminate/validation` on Laravel 10.x / 11.x × PHP 8.1 / 8.2 / 8.3 in CI.

- **Engine**: the rule runs in `ValidatorEngine`, which covers every rule vi/validation supports.
- **Native**: the rule can be inlined by `NativeCompiler`, i.e. its class implements `NativeCompilableInterface`. A schema runs natively only if *every* rule in it is native. Engine support never implies native support.
- **Parity**: ✅ pass/fail and failed fields match Laravel (parity-tested); 🟡 matches except for the noted cases; 🔴 intentionally different (pinned by a divergence test); ➖ no Laravel counterpart.
- **Nested / wildcard**: Dot paths of any depth ('parent.child', 'a.b.c') resolve like Laravel's dot notation on both the engine and native paths, including presence checks for sometimes and conditional rules. Wildcard segments ('items.*.sku') are not implemented: a wildcard field name never resolves against real data, so implicit rules (e.g. required) on it fail unconditionally and non-implicit rules silently no-op. Pinned by tests/Unit/Parity/NestedWildcardParityTest.php via assertDivergence().

## Core & presence

| Rule | Engine | Native | Parity | Laravel | Dependencies | Notes |
| :--- | :---: | :---: | :--- | :--- | :--- | :--- |
| `bail` | yes | yes | ✅ verified | all | — |  |
| `filled` | yes | no | ✅ verified | all | — |  |
| `missing` | yes | no | ✅ verified | all | — |  |
| `nullable` | yes | yes | ✅ verified | all | — |  |
| `present` | yes | no | ✅ verified | all | — |  |
| `required` | yes | yes | ✅ verified | all | — |  |
| `sometimes` | yes | yes | ✅ verified | all | — |  |

## Conditionals

| Rule | Engine | Native | Parity | Laravel | Dependencies | Notes |
| :--- | :---: | :---: | :--- | :--- | :--- | :--- |
| `accepted_if` | yes | no | ✅ verified | all | — | Multiple dependent values (rule:other,v1,v2,...) and Laravel's typed matching ('true'/'false'/'null' for bool/null input, loose otherwise) are supported. |
| `declined_if` | yes | no | ✅ verified | all | — | Multiple dependent values (rule:other,v1,v2,...) and Laravel's typed matching ('true'/'false'/'null' for bool/null input, loose otherwise) are supported. |
| `exclude` | yes | no | ✅ verified | all | — |  |
| `exclude_if` | yes | no | ✅ verified | all | — | Multiple dependent values (rule:other,v1,v2,...) and Laravel's typed matching ('true'/'false'/'null' for bool/null input, loose otherwise) are supported. |
| `exclude_unless` | yes | no | ✅ verified | all | — | Multiple dependent values (rule:other,v1,v2,...) and Laravel's typed matching ('true'/'false'/'null' for bool/null input, loose otherwise) are supported. |
| `exclude_with` | yes | no | ✅ verified | all | — | Crashed via rule string before issue #5 (no case in LaravelRuleParser); fixed. |
| `exclude_without` | yes | no | ✅ verified | all | — | Crashed via rule string before issue #5 (no case in LaravelRuleParser); fixed. |
| `missing_if` | yes | no | ✅ verified | all | — | Multiple dependent values (rule:other,v1,v2,...) and Laravel's typed matching ('true'/'false'/'null' for bool/null input, loose otherwise) are supported. |
| `missing_unless` | yes | no | ✅ verified | all | — | Multiple dependent values (rule:other,v1,v2,...) and Laravel's typed matching ('true'/'false'/'null' for bool/null input, loose otherwise) are supported. |
| `missing_with` | yes | no | ✅ verified | all | — | LaravelRuleParser previously built this with zero fields (variadic ctor, silent no-op); fixed as part of issue #5. |
| `missing_with_all` | yes | no | ✅ verified | all | — | Same variadic-args fix as missing_with. |
| `present_if` | yes | no | ✅ verified | 10.x+ | — |  |
| `present_unless` | yes | no | ✅ verified | 10.x+ | — |  |
| `present_with` | yes | no | ✅ verified | 10.x+ | — |  |
| `present_with_all` | yes | no | ✅ verified | 10.x+ | — |  |
| `prohibited` | yes | no | ✅ verified | all | — |  |
| `prohibited_if` | yes | no | ✅ verified | all | — | Uses Laravel's typed dependent-value matching (JSON ints/bools/null match their string parameters); previously required_if/required_unless compared strictly and never matched non-string input. |
| `prohibited_if_accepted` | yes | no | ✅ verified | 11.x+ | — |  |
| `prohibited_if_declined` | yes | no | ✅ verified | 11.x+ | — |  |
| `prohibited_unless` | yes | no | ✅ verified | all | — | Uses Laravel's typed dependent-value matching (JSON ints/bools/null match their string parameters); previously required_if/required_unless compared strictly and never matched non-string input. |
| `prohibits` | yes | no | ✅ verified | all | — | LaravelRuleParser previously built this with zero fields (variadic ctor, silent no-op); fixed as part of issue #5. |
| `required_if` | yes | no | ✅ verified | all | — | Uses Laravel's typed dependent-value matching (JSON ints/bools/null match their string parameters); previously required_if/required_unless compared strictly and never matched non-string input. |
| `required_if_accepted` | yes | no | ✅ verified | all | — | LaravelRuleParser had no case for this rule string (crashed with ArgumentCountError); fixed as part of issue #5. |
| `required_if_declined` | yes | no | ✅ verified | 11.x+ | — |  |
| `required_unless` | yes | no | ✅ verified | all | — | Uses Laravel's typed dependent-value matching (JSON ints/bools/null match their string parameters); previously required_if/required_unless compared strictly and never matched non-string input. |
| `required_with` | yes | no | ✅ verified | all | — |  |
| `required_with_all` | yes | no | ✅ verified | all | — |  |
| `required_without` | yes | no | ✅ verified | all | — |  |
| `required_without_all` | yes | no | ✅ verified | all | — |  |

## Acceptance

| Rule | Engine | Native | Parity | Laravel | Dependencies | Notes |
| :--- | :---: | :---: | :--- | :--- | :--- | :--- |
| `accepted` | yes | no | ✅ verified | all | — |  |
| `declined` | yes | no | ✅ verified | all | — | Fixed as part of issue #5: ValidatorEngine/NativeCompiler's implicit-rule whitelists included AcceptedRule but not DeclinedRule, so 'declined' silently passed when the field was entirely missing instead of failing like Laravel does. |

## Types

| Rule | Engine | Native | Parity | Laravel | Dependencies | Notes |
| :--- | :---: | :---: | :--- | :--- | :--- | :--- |
| `array` | yes | yes | ✅ verified | all | — |  |
| `boolean` | yes | yes | ✅ verified | all | — |  |
| `date` | yes | no | ✅ verified | all | — |  |
| `decimal` | yes | no | ✅ verified | all | — | Crashed via rule string before issue #5 (no case in LaravelRuleParser); fixed. |
| `enum` | yes | no | ✅ verified | all | — | Laravel has no native 'enum:ClassName' string-rule grammar (Enum is normally applied as a Rule object). The parity test passes an equivalent [new Illuminate\Validation\Rules\Enum(...)] to Laravel and the string form to Fast rather than sharing one rules array; underlying validation semantics match. Rule string previously crashed (no case in LaravelRuleParser); fixed as part of issue #5. |
| `integer` | yes | yes | ✅ verified | all | — |  |
| `json` | yes | yes | ✅ verified | all | — |  |
| `list` | yes | no | ✅ verified | 11.0+ | — |  |
| `numeric` | yes | yes | ✅ verified | all | — |  |
| `string` | yes | yes | ✅ verified | all | — |  |

## Strings

| Rule | Engine | Native | Parity | Laravel | Dependencies | Notes |
| :--- | :---: | :---: | :--- | :--- | :--- | :--- |
| `active_url` | yes | no | ✅ verified | all | network (DNS) |  |
| `alpha` | yes | yes | ✅ verified | all | — |  |
| `alpha_dash` | yes | yes | ✅ verified | all | — |  |
| `alpha_num` | yes | yes | ✅ verified | all | — |  |
| `ascii` | yes | no | ✅ verified | all | — |  |
| `doesnt_end_with` | yes | no | ✅ verified | all | — |  |
| `doesnt_start_with` | yes | no | ✅ verified | all | — |  |
| `email` | yes | yes | ✅ verified | all | — |  |
| `ends_with` | yes | no | ✅ verified | all | — |  |
| `hex_color` | yes | no | ✅ verified | 10.x+ | — |  |
| `ip` | yes | yes | ✅ verified | all | — |  |
| `ipv4` | yes | yes | ✅ verified | all | — | Fixed: the ipv4 alias used to build an unrestricted IpRule and behave like plain 'ip'. Failures are reported as 'ipv4', like Laravel. |
| `ipv6` | yes | yes | ✅ verified | all | — | Fixed together with ipv4; failures are reported as 'ipv6'. |
| `lowercase` | yes | no | ✅ verified | all | — |  |
| `mac_address` | yes | no | ✅ verified | all | — |  |
| `not_regex` | yes | no | ✅ verified | all | — |  |
| `regex` | yes | no | ✅ verified | all | — |  |
| `starts_with` | yes | no | ✅ verified | all | — |  |
| `ulid` | yes | no | ✅ verified | all | — |  |
| `uppercase` | yes | no | ✅ verified | all | — |  |
| `url` | yes | yes | ✅ verified | all | — |  |
| `uuid` | yes | no | ✅ verified | all | — |  |

## Numbers & size

| Rule | Engine | Native | Parity | Laravel | Dependencies | Notes |
| :--- | :---: | :---: | :--- | :--- | :--- | :--- |
| `between` | yes | no | ✅ verified | all | — |  |
| `digits` | yes | no | ✅ verified | all | — |  |
| `digits_between` | yes | no | ✅ verified | all | — | Crashed via rule string before issue #5 (LaravelRuleParser cast params to float, but DigitsBetweenRule's constructor requires int); fixed. |
| `max` | yes | yes | ✅ verified | all | — |  |
| `max_digits` | yes | no | ✅ verified | all | — |  |
| `min` | yes | yes | ✅ verified | all | — |  |
| `min_digits` | yes | no | ✅ verified | all | — |  |
| `multiple_of` | yes | no | ✅ verified | all | — |  |
| `size` | yes | no | ✅ verified | all | — |  |

## Comparison

| Rule | Engine | Native | Parity | Laravel | Dependencies | Notes |
| :--- | :---: | :---: | :--- | :--- | :--- | :--- |
| `confirmed` | yes | no | ✅ verified | all | — |  |
| `different` | yes | no | ✅ verified | all | — |  |
| `gt` | yes | no | ✅ verified | all | — |  |
| `gte` | yes | no | ✅ verified | all | — |  |
| `in` | yes | no | ✅ verified | all | — |  |
| `lt` | yes | no | ✅ verified | all | — |  |
| `lte` | yes | no | ✅ verified | all | — |  |
| `not_in` | yes | no | ✅ verified | all | — |  |
| `same` | yes | no | ✅ verified | all | — |  |

## Dates

| Rule | Engine | Native | Parity | Laravel | Dependencies | Notes |
| :--- | :---: | :---: | :--- | :--- | :--- | :--- |
| `after` | yes | no | ✅ verified | all | — |  |
| `after_or_equal` | yes | no | ✅ verified | all | — |  |
| `before` | yes | no | ✅ verified | all | — |  |
| `before_or_equal` | yes | no | ✅ verified | all | — |  |
| `date_equals` | yes | no | ✅ verified | all | — |  |
| `date_format` | yes | no | ✅ verified | all | — |  |
| `timezone` | yes | no | ✅ verified | all | — |  |

## Arrays

| Rule | Engine | Native | Parity | Laravel | Dependencies | Notes |
| :--- | :---: | :---: | :--- | :--- | :--- | :--- |
| `contains` | yes | no | ✅ verified | 11.x+ | — |  |
| `distinct` | yes | no | 🟡 partial | all | — | Laravel's distinct compares wildcard siblings ('tags.*' => 'distinct') and never fails on a plain field; vi/validation (which has no wildcards) checks the array's own elements ('tags' => 'array\|distinct'). Equivalent for the idiomatic uses, compared via assertEquivalentParity() in ArrayParityTest. Strict mode uses ===. |
| `in_array` | yes | no | ✅ verified | all | — | Other path may use '*' wildcards (in_array:allowed.*); the value is compared loosely against every matching leaf, like Laravel's Str::is() + in_array(). |
| `required_array_keys` | yes | no | ✅ verified | all | — | Crashed via rule string before issue #5 (RequiredArrayKeysRule takes variadic string ...$keys, but LaravelRuleParser wrongly grouped it with array-taking rules like required_with); fixed. |

## Files

| Rule | Engine | Native | Parity | Laravel | Dependencies | Notes |
| :--- | :---: | :---: | :--- | :--- | :--- | :--- |
| `dimensions` | yes | no | ✅ verified | all | uploaded files | Crashed via rule string before issue #5 (no case in LaravelRuleParser); fixed with a key=value constraint parser (min_width, max_width, min_height, max_height, width, height, ratio). |
| `extensions` | yes | no | ✅ verified | all | uploaded files | Two fixes as part of issue #5: (1) LaravelRuleParser previously built this with zero extensions (variadic ctor, silent no-op). (2) ExtensionsRule compared the extension of the file's real temp path ('/tmp/phpXXXXXX', no extension) instead of the uploaded file's original client filename, so it always failed for real uploads; now prefers getClientOriginalExtension() when available, matching Laravel's validateExtensions(). |
| `file` | yes | no | ✅ verified | all | uploaded files |  |
| `image` | yes | no | ✅ verified | all | uploaded files |  |
| `max_file_size` | yes | no | ✅ verified | all | uploaded files | Same as min_file_size: Laravel overloads 'max:N'; vi/validation names it separately. Compared via assertEquivalentParity(). |
| `mimes` | yes | no | ✅ verified | all | uploaded files |  |
| `mimetypes` | yes | no | ✅ verified | all | uploaded files | LaravelRuleParser previously built this with zero types (MimetypesRule takes variadic string ...$types, but was wrongly grouped with array-taking 'mimes'; silent no-op); fixed as part of issue #5. |
| `min_file_size` | yes | no | ✅ verified | all | uploaded files | Crashed via rule string before issue #5 (no case in LaravelRuleParser; only max_file_size was handled). Fixed. Different rule name than Laravel, same semantics: Laravel overloads 'min:N' to mean kilobytes when the value is a File instance; vi/validation gives it a distinct name. Compared via assertEquivalentParity() in FileParityTest ('file\|min:N' vs 'file\|min_file_size:N'). |

## Database

| Rule | Engine | Native | Parity | Laravel | Dependencies | Notes |
| :--- | :---: | :---: | :--- | :--- | :--- | :--- |
| `exists` | yes | no | 🟡 partial | all | database | Full table/column/connection/extra-where grammar, Rule::exists() objects and array values (every value must exist). FastValidationServiceProvider backs it with Laravel's own presence verifier; without a database validator the rule throws (fails closed) instead of passing. Not supported: an Eloquent model class as the table parameter. |
| `unique` | yes | no | 🟡 partial | all | database | Same wiring as exists, plus ignore-id/id-column/extra-where grammar and Rule::unique()->ignore(). Not supported: the `[field]` bracket syntax for a per-row dynamic ignore id, and an Eloquent model class as the table parameter. |

## Auth

| Rule | Engine | Native | Parity | Laravel | Dependencies | Notes |
| :--- | :---: | :---: | :--- | :--- | :--- | :--- |
| `current_password` | yes | no | 🟡 partial | all | auth | Backed by Laravel's auth + hasher via FastValidationServiceProvider; guests and a missing hasher fail (fails closed, like Laravel). The optional guard parameter (current_password:api) is ignored: the default guard is always used. |
| `password` | yes | no | ✅ verified | all | network (uncompromised check only) |  |

## vi/validation extensions

| Rule | Engine | Native | Parity | Laravel | Dependencies | Notes |
| :--- | :---: | :---: | :--- | :--- | :--- | :--- |
| `country` | yes | no | ➖ n/a | all | — | vi/validation-only extension; Laravel has no built-in 'country' rule to compare against. Tested for internal correctness only, not cross-validated. |
| `language` | yes | no | ➖ n/a | all | — | vi/validation-only extension; Laravel has no built-in 'language' rule to compare against. Tested for internal correctness only, not cross-validated. |

## Internal

| Rule | Engine | Native | Parity | Laravel | Dependencies | Notes |
| :--- | :---: | :---: | :--- | :--- | :--- | :--- |
| `closure` | yes | no | ➖ n/a | all | — | Internal wrapper for user-supplied Laravel-style closures, never a user-typed rule-string name. Exercised functionally in tests/Unit/Parity/ClosureParityTest.php. Fixed as part of issue #5: SchemaValidator::validate() crashed (Exception: Serialization of 'Closure' is not allowed) on every call for any schema containing an inline closure rule, because NativeCompiler::generateKey() ran a plain serialize() on the raw rules array for its native-cache-lookup key. This made the documented closure-rule feature entirely unusable via SchemaValidator::validate() (and therefore FastValidator too). generateKey() now falls back to a closure-safe normalization on serialize() failure. |
| `conditional` | yes | no | ➖ n/a | all | — | Internal mechanism behind FieldDefinition::when(), never a user-typed rule-string name. Its Laravel-equivalent behavior (Illuminate's Validator::sometimes()/conditional closures) is exercised functionally in tests/Unit/Parity/ClosureParityTest.php rather than compared as a standalone named rule. |
