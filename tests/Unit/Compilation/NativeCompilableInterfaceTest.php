<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Compilation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Vi\Validation\Compilation\NativeCompilationContext;
use Vi\Validation\Compilation\NativeCompiler;
use Vi\Validation\Execution\ValidationContext;
use Vi\Validation\Execution\ValidatorEngine;
use Vi\Validation\Rules\NativeCompilableInterface;
use Vi\Validation\Rules\RuleInterface;
use Vi\Validation\Schema\FieldDefinition;
use Vi\Validation\Validator;

/**
 * Focused tests for the NativeCompilableInterface contract:
 * - NativeCompilationContext (the codegen "mechanics" half).
 * - Every built-in rule's compileNative() output against ValidatorEngine,
 *   to keep the two execution paths provably in sync.
 * - The documented extension point for custom, user-defined rules.
 */
#[Group('native')]
class NativeCompilableInterfaceTest extends TestCase
{
    // --- NativeCompilationContext ---

    public function testEmitErrorGeneratesGuardedErrorBlock(): void
    {
        $context = new NativeCompilationContext('name', '$val_name', '    ');

        $code = $context->emitError('$val_name === null', 'required');

        $this->assertSame(
            "    if (\$val_name === null) {\n"
            . "        \$errors['name'][] = ['rule' => 'required', 'message' => null];\n"
            . "        \$hasErrors = true;\n"
            . "    }\n",
            $code
        );
    }

    public function testEmitErrorIncludesParamsExpressionWhenGiven(): void
    {
        $context = new NativeCompilationContext('age', '$val_age', '');

        $code = $context->emitError('true', 'min', "['type' => 'numeric', 'min' => 18]");

        $this->assertStringContainsString(
            "'params' => ['type' => 'numeric', 'min' => 18]",
            $code
        );
    }

    public function testFieldNameContainingQuotesIsSafelyEscaped(): void
    {
        $context = new NativeCompilationContext("weird'field", '$v', '');

        $code = $context->errorStatement('required');

        // The generated code must be syntactically valid PHP.
        $closure = eval('return function() {' . "\n\$errors = [];\n\$hasErrors = false;\n" . $code . "\nreturn [\$errors, \$hasErrors];\n};");
        [$errors, $hasErrors] = $closure();

        $this->assertTrue($hasErrors);
        $this->assertArrayHasKey("weird'field", $errors);
    }

    public function testWithIndentReturnsNewContextAtDifferentIndentationWithoutMutatingOriginal(): void
    {
        $context = new NativeCompilationContext('f', '$v', '    ');
        $deeper = $context->withIndent('        ');

        $this->assertSame('    ', $context->indent);
        $this->assertSame('        ', $deeper->indent);
        $this->assertSame('f', $deeper->fieldName);
        $this->assertSame('$v', $deeper->valName);
    }

    // --- isSupported() is driven purely by the interface, not a class list ---

    public function testIsSupportedIsTrueForAnyRuleImplementingTheInterfaceRegardlessOfClassName(): void
    {
        $rule = new class implements RuleInterface, NativeCompilableInterface {
            public function validate(mixed $value, string $field, ValidationContext $context): ?array
            {
                return null;
            }

            public function compileNative(NativeCompilationContext $context): string
            {
                return '';
            }

            public function isImplicitForNative(): bool
            {
                return false;
            }
        };

        $this->assertTrue((new NativeCompiler())->isSupported($rule));
    }

    public function testIsSupportedIsFalseForARuleThatDoesNotImplementTheInterfaceEvenIfItLooksInlinable(): void
    {
        $rule = new class implements RuleInterface {
            public function validate(mixed $value, string $field, ValidationContext $context): ?array
            {
                return null;
            }
        };

        $this->assertFalse((new NativeCompiler())->isSupported($rule));
    }

    /**
     * Acceptance criterion: custom rules have a documented extension point.
     * A user-defined rule implementing NativeCompilableInterface must become
     * natively compilable with zero registry/NativeCompiler changes, and the
     * generated code must actually run and agree with validate().
     */
    public function testCustomRuleImplementingTheInterfaceIsNativelyCompiledAndRunsCorrectly(): void
    {
        $rule = new class implements RuleInterface, NativeCompilableInterface {
            public function validate(mixed $value, string $field, ValidationContext $context): ?array
            {
                if ($value !== null && $value !== 'ok') {
                    return ['rule' => 'must_be_ok'];
                }
                return null;
            }

            public function compileNative(NativeCompilationContext $context): string
            {
                $v = $context->valName;
                return $context->emitError("{$v} !== null && {$v} !== 'ok'", 'must_be_ok');
            }

            public function isImplicitForNative(): bool
            {
                return false;
            }
        };

        $schema = Validator::schema()->field('status')->rules($rule)->compile();

        $compiler = new NativeCompiler();
        $this->assertTrue($compiler->canCompile($schema));

        $closure = $this->evalCompiledSchema($compiler, $schema);

        $this->assertTrue($closure(['status' => 'ok'])['valid']);
        $this->assertFalse($closure(['status' => 'nope'])['valid']);
    }

    // --- Differential tests: native output must agree with ValidatorEngine ---

    /**
     * @dataProvider builtInRuleProvider
     */
    public function testBuiltInRuleNativeOutputAgreesWithValidatorEngine(
        callable $configureField,
        array $values
    ): void {
        $builder = Validator::schema()->field('value');
        $configureField($builder);
        $schema = $builder->compile();

        $compiler = new NativeCompiler();
        $this->assertTrue(
            $compiler->canCompile($schema),
            'Expected schema to be natively compilable: ' . implode(', ', $compiler->findUnsupportedRules($schema))
        );

        $closure = $this->evalCompiledSchema($compiler, $schema);
        $engine = new ValidatorEngine();

        foreach ($values as $value) {
            $nativeValid = $closure(['value' => $value])['valid'];
            $engineValid = $engine->validate($schema, ['value' => $value])->isValid();

            $this->assertSame(
                $engineValid,
                $nativeValid,
                'Mismatch for value ' . var_export($value, true)
            );
        }
    }

    public static function builtInRuleProvider(): array
    {
        return [
            'required' => [fn (FieldDefinition $f) => $f->required(), [null, '', [], 'x', 0, false]],
            'string' => [fn (FieldDefinition $f) => $f->string(), [null, 'abc', 123, 1.5, [], true]],
            'integer' => [fn (FieldDefinition $f) => $f->integer(), [null, 5, '5', '-5', '5.5', 'x', '']],
            'numeric' => [fn (FieldDefinition $f) => $f->numeric(), [null, 5, '5', '5.5', 'x', []]],
            'boolean' => [fn (FieldDefinition $f) => $f->boolean(), [null, true, false, 0, 1, '0', '1', '2', 'yes']],
            'array' => [fn (FieldDefinition $f) => $f->array(), [null, [], ['a'], 'x', 5]],
            'email' => [fn (FieldDefinition $f) => $f->email(), [null, 'a@b.com', 'not-an-email', 123]],
            'url' => [fn (FieldDefinition $f) => $f->url(), [null, 'https://example.com', 'not a url', 123]],
            'ip (any)' => [fn (FieldDefinition $f) => $f->ip(), [null, '127.0.0.1', '::1', 'not-an-ip']],
            'ip (v4)' => [fn (FieldDefinition $f) => $f->ipv4(), [null, '127.0.0.1', '::1', 'not-an-ip']],
            'ip (v6)' => [fn (FieldDefinition $f) => $f->ipv6(), [null, '127.0.0.1', '::1', 'not-an-ip']],
            'json' => [fn (FieldDefinition $f) => $f->json(), [null, '{"a":1}', '{invalid', 123]],
            'min (string)' => [fn (FieldDefinition $f) => $f->min(3), [null, 'ab', 'abc', 'abcd']],
            'min (numeric)' => [fn (FieldDefinition $f) => $f->numeric()->min(3), [null, '1', '3', '4', 1, 3, 4]],
            'min (array)' => [fn (FieldDefinition $f) => $f->array()->min(2), [null, [], ['a'], ['a', 'b']]],
            'max (string)' => [fn (FieldDefinition $f) => $f->max(3), [null, 'ab', 'abc', 'abcd']],
            'max (numeric)' => [fn (FieldDefinition $f) => $f->numeric()->max(3), [null, '1', '3', '4', 1, 3, 4]],
            'max (array)' => [fn (FieldDefinition $f) => $f->array()->max(2), [null, [], ['a'], ['a', 'b', 'c']]],
            'alpha' => [fn (FieldDefinition $f) => $f->alpha(), [null, 'abc', 'café', 'ab3', 'a b']],
            'alphanumeric' => [fn (FieldDefinition $f) => $f->alphanumeric(), [null, 'abc123', '12345', 12345, 'ab-c', 'café1']],
            'alphaDash' => [fn (FieldDefinition $f) => $f->alphaDash(), [null, 'abc-123_x', 'ab c', 123, 'café-1']],
        ];
    }

    private function evalCompiledSchema(NativeCompiler $compiler, \Vi\Validation\Execution\CompiledSchema $schema): \Closure
    {
        $code = $compiler->compile($schema);
        // Strip the leading "<?php" tag so it can be eval()'d in this scope.
        $closure = eval(substr($code, strlen('<?php')));
        $this->assertInstanceOf(\Closure::class, $closure);

        return $closure;
    }
}
