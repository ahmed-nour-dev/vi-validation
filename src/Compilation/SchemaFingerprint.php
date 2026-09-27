<?php

declare(strict_types=1);

namespace Vi\Validation\Compilation;

use Closure;
use ReflectionClass;
use ReflectionProperty;
use UnitEnum;
use Vi\Validation\Execution\CompiledField;
use Vi\Validation\Execution\CompiledSchema;

/**
 * Canonical, deterministic identity of a compiled schema.
 *
 * The fingerprint is derived from the *compiled* schema - the field list, each field's
 * execution flags (nullable/bail/sometimes/exclusion) and every resolved rule's class and
 * state - never from the user's input format. So `['a' => 'required|email']`,
 * `['a' => ['required', 'email']]` and `->field('a')->required()->email()` all fingerprint
 * identically, and two schemas that would validate differently can never share one.
 *
 * Two hashes are exposed:
 *
 * - {@see $schemaHash}: pure validation semantics. Stable across PHP versions, package
 *   versions and processes. Use it as a logical schema id.
 * - {@see $artifactKey}: schemaHash + {@see FORMAT_VERSION} + NativeCompiler::COMPILER_VERSION
 *   + PHP_VERSION_ID. Use it to key generated artifacts (native PHP closures, serialized
 *   schemas): any compiler change or PHP upgrade yields a new key, so stale artifacts are
 *   simply never looked up again.
 *
 * Rule state is captured by reflecting over every property of the rule object (including
 * inherited private ones) and encoding it canonically and type-tagged ("1" and 1 differ).
 * Nested objects and enums are handled recursively.
 *
 * A schema whose identity cannot be captured deterministically - one containing a Closure
 * (inline closure rules, `when()` conditions), a resource, a non-serializable internal
 * object, or a reference cycle - is marked {@see $stable} = false. Its hashes are then salted
 * with a per-process random value and the object ids of the schema, so they can never
 * collide with another schema (in this process or any other) and must never be persisted.
 * Callers that write artifacts to shared storage must check {@see $stable} first.
 *
 * Messages, locale and custom attribute names are deliberately *not* part of the
 * fingerprint: they only affect how an error is rendered (resolved after validation), not
 * whether a row passes or which rules fail.
 */
final class SchemaFingerprint
{
    /**
     * Bump whenever the canonical encoding below changes, so fingerprints computed by an
     * older package version are never mistaken for current ones.
     */
    public const FORMAT_VERSION = '1';

    private static ?string $processSalt = null;

    private function __construct(
        public readonly string $schemaHash,
        public readonly string $artifactKey,
        public readonly bool $stable,
        /** @var list<string> Why the fingerprint is unstable (empty when stable). */
        public readonly array $unstableReasons,
    ) {
    }

    public static function of(CompiledSchema $schema): self
    {
        $unstable = [];
        $canonical = 'vi-schema:' . self::FORMAT_VERSION . "\n";

        foreach ($schema->getFields() as $field) {
            $canonical .= self::encodeField($field, $unstable) . "\n";
        }

        if ($unstable !== []) {
            // Never let an unstable schema share an identity with anything else: salt with a
            // process-unique random value plus this schema object's id.
            $canonical .= 'unstable:' . self::processSalt() . ':' . spl_object_id($schema);
        }

        $schemaHash = hash('sha256', $canonical);
        $artifactKey = hash(
            'sha256',
            $schemaHash . '|' . self::FORMAT_VERSION . '|' . NativeCompiler::COMPILER_VERSION . '|' . PHP_VERSION_ID
        );

        return new self($schemaHash, $artifactKey, $unstable === [], array_values(array_unique($unstable)));
    }

    /**
     * @param list<string> $unstable
     */
    private static function encodeField(CompiledField $field, array &$unstable): string
    {
        $parts = [
            'field:' . self::encodeString($field->getName()),
            'n' . (int) $field->isNullable(),
            'b' . (int) $field->isBail(),
            's' . (int) $field->isSometimes(),
        ];

        $context = $field->getName();

        if ($field->isAlwaysExcluded()) {
            $parts[] = 'X';
        }

        foreach ($field->getExclusionRules() as $exclusion) {
            $parts[] = 'x:' . self::encodeValue($exclusion, $unstable, $context, []);
        }

        foreach ($field->getRules() as $rule) {
            $parts[] = 'r:' . self::encodeValue($rule, $unstable, $context, []);
        }

        return implode('|', $parts);
    }

    /**
     * @param list<string> $unstable
     * @param array<int, true> $visiting object ids on the current path (cycle detection)
     */
    private static function encodeValue(mixed $value, array &$unstable, string $context, array $visiting): string
    {
        if ($value === null) {
            return 'N';
        }
        if (is_bool($value)) {
            return $value ? 'T' : 'F';
        }
        if (is_int($value)) {
            return 'i' . $value;
        }
        if (is_float($value)) {
            // var_export round-trips floats exactly (incl. INF/NAN spellings).
            return 'd' . var_export($value, true);
        }
        if (is_string($value)) {
            return self::encodeString($value);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[] = (is_int($k) ? 'i' . $k : self::encodeString($k))
                    . '=>' . self::encodeValue($v, $unstable, $context, $visiting);
            }
            return 'a{' . implode(',', $out) . '}';
        }
        if ($value instanceof Closure) {
            $unstable[] = "{$context}: closure";
            return 'closure';
        }
        if ($value instanceof UnitEnum) {
            return 'e' . self::encodeString(get_class($value) . '::' . $value->name);
        }
        if (is_object($value)) {
            return self::encodeObject($value, $unstable, $context, $visiting);
        }

        $unstable[] = "{$context}: " . get_debug_type($value);
        return 'unsupported';
    }

    /**
     * @param list<string> $unstable
     * @param array<int, true> $visiting
     */
    private static function encodeObject(object $object, array &$unstable, string $context, array $visiting): string
    {
        $id = spl_object_id($object);
        $class = get_class($object);

        if (isset($visiting[$id])) {
            $unstable[] = "{$context}: reference cycle in {$class}";
            return 'cycle';
        }
        $visiting[$id] = true;

        $reflection = new ReflectionClass($object);

        if ($reflection->isInternal() || $reflection->isAnonymous()) {
            if ($reflection->isAnonymous()) {
                $unstable[] = "{$context}: anonymous class";
                return 'anon';
            }
            // Internal classes (DateTimeImmutable, ArrayObject, ...) keep their state outside
            // declared properties; serialize() is their canonical form when it's supported.
            try {
                return 'o' . self::encodeString($class) . self::encodeString(serialize($object));
            } catch (\Throwable) {
                $unstable[] = "{$context}: non-serializable {$class}";
                return 'unsupported';
            }
        }

        $props = [];
        for ($r = $reflection; $r !== false; $r = $r->getParentClass()) {
            foreach ($r->getProperties() as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $r->getName()) {
                    continue;
                }
                $props[] = $r->getName() . '::' . $property->getName() . '='
                    . self::encodeProperty($property, $object, $unstable, $context, $visiting);
            }
        }

        return 'o' . self::encodeString($class) . '{' . implode(',', $props) . '}';
    }

    /**
     * @param list<string> $unstable
     * @param array<int, true> $visiting
     */
    private static function encodeProperty(
        ReflectionProperty $property,
        object $object,
        array &$unstable,
        string $context,
        array $visiting
    ): string {
        $property->setAccessible(true);

        if (!$property->isInitialized($object)) {
            return 'U';
        }

        return self::encodeValue($property->getValue($object), $unstable, $context, $visiting);
    }

    private static function encodeString(string $value): string
    {
        // Length-prefixed so no concatenation of strings can mimic another.
        return 's' . strlen($value) . ':' . $value;
    }

    private static function processSalt(): string
    {
        return self::$processSalt ??= bin2hex(random_bytes(16)) . ':' . getmypid();
    }
}
