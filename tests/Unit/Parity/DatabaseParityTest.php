<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Parity;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\Attributes\Group;
use Vi\Validation\Execution\ValidatorEngine;
use Vi\Validation\Laravel\LaravelRuleParser;
use Vi\Validation\Rules\DatabaseValidatorInterface;
use Vi\Validation\Rules\RuleRegistry;
use Vi\Validation\SchemaValidator;
use Vi\Validation\Schema\SchemaBuilder;
use Vi\Validation\Tests\Unit\Parity\Support\ParityTestCase;

/**
 * Covers RuleId: exists, unique.
 *
 * KNOWN GAP (documented, not fixed by issue #5): Vi\Validation\Laravel\FastValidatorFactory
 * never wires a DatabaseValidatorInterface into the ValidatorEngine it builds, and exposes no
 * config option to supply one. That means exists/unique rule strings used through
 * FastValidator::make()/FastValidatorFactory (the actual Laravel integration surface) always
 * silently pass - ExistsRule/UniqueRule return null (success) whenever
 * ValidationContext::getDatabaseValidator() is null, regardless of real data. This test
 * exercises the rule classes and the LaravelRuleParser grammar fix directly via the lower-level
 * SchemaValidator/ValidatorEngine API (which does support setDatabaseValidator()), since that
 * is the only currently-working path to a database-backed check. See resources/
 * compatibility-matrix.json for "exists"/"unique".
 */
#[Group('laravel')]
class DatabaseParityTest extends ParityTestCase
{
    private ?Capsule $capsule = null;

    protected function tearDown(): void
    {
        $this->capsule = null;
        parent::tearDown();
    }

    private function capsule(): Capsule
    {
        if ($this->capsule === null) {
            $capsule = new Capsule();
            $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
            $capsule->setAsGlobal();

            $conn = $capsule->getConnection();
            $conn->statement('create table users (id integer primary key, email text, status text)');
            $conn->table('users')->insert(['id' => 1, 'email' => 'ada@example.com', 'status' => 'active']);
            $conn->table('users')->insert(['id' => 2, 'email' => 'grace@example.com', 'status' => 'inactive']);

            $this->capsule = $capsule;
        }

        return $this->capsule;
    }

    private function laravelWithDb(array $data, array $rules): \Illuminate\Contracts\Validation\Validator
    {
        $translator = new Translator(new ArrayLoader(), 'en');
        $factory = new Factory($translator, new Container());
        $factory->setPresenceVerifier(new DatabasePresenceVerifier($this->capsule()->getDatabaseManager()));

        return $factory->make($data, $rules);
    }

    private function fastWithDb(array $data, array $rules): \Vi\Validation\Execution\ValidationResult
    {
        $registry = new RuleRegistry();
        $registry->registerBuiltInRules();
        $parser = new LaravelRuleParser($registry);
        $builder = new SchemaBuilder();
        $builder->setRulesArray($rules);

        foreach ($rules as $field => $definition) {
            $builder->field((string) $field)->rules(...$parser->parse($definition, (string) $field));
        }

        $engine = new ValidatorEngine();
        $engine->setDatabaseValidator($this->capsuleDatabaseValidator());

        $validator = new SchemaValidator($builder->compile(), $engine);

        return $validator->validate($data);
    }

    private function capsuleDatabaseValidator(): DatabaseValidatorInterface
    {
        $capsule = $this->capsule();

        return new class ($capsule) implements DatabaseValidatorInterface {
            public function __construct(private Capsule $capsule)
            {
            }

            public function exists(string $table, string $column, mixed $value, array $extraConstraints = [], ?string $connection = null): bool
            {
                $query = $this->capsule->getConnection($connection)->table($table)->where($column, $value);
                foreach ($extraConstraints as $col => $val) {
                    $query->where($col, $val);
                }

                return $query->exists();
            }

            public function unique(string $table, string $column, mixed $value, mixed $ignoreId = null, string $idColumn = 'id', array $extraConstraints = [], ?string $connection = null): bool
            {
                $query = $this->capsule->getConnection($connection)->table($table)->where($column, $value);
                if ($ignoreId !== null) {
                    $query->where($idColumn, '!=', $ignoreId);
                }
                foreach ($extraConstraints as $col => $val) {
                    $query->where($col, $val);
                }

                return !$query->exists();
            }
        };
    }

    private function assertDbParity(array $rules, array $data): void
    {
        $laravel = $this->laravelWithDb($data, $rules);
        $fast = $this->fastWithDb($data, $rules);

        self::assertSame(
            $laravel->fails(),
            !$fast->isValid(),
            'Overall pass/fail mismatch for rules=' . json_encode($rules) . ' data=' . json_encode($data)
        );
    }

    public function testExistsPassesForKnownValue(): void
    {
        $this->assertDbParity(['email' => 'exists:users,email'], ['email' => 'ada@example.com']);
    }

    public function testExistsFailsForUnknownValue(): void
    {
        $this->assertDbParity(['email' => 'exists:users,email'], ['email' => 'nobody@example.com']);
    }

    public function testExistsDefaultsColumnToAttributeName(): void
    {
        $this->assertDbParity(['email' => 'exists:users'], ['email' => 'ada@example.com']);
    }

    public function testExistsWithExtraWhereConstraintPasses(): void
    {
        $this->assertDbParity(
            ['email' => 'exists:users,email,status,active'],
            ['email' => 'ada@example.com']
        );
    }

    public function testExistsWithExtraWhereConstraintFails(): void
    {
        $this->assertDbParity(
            ['email' => 'exists:users,email,status,active'],
            ['email' => 'grace@example.com']
        );
    }

    public function testUniquePassesForNewValue(): void
    {
        $this->assertDbParity(['email' => 'unique:users,email'], ['email' => 'new@example.com']);
    }

    public function testUniqueFailsForExistingValue(): void
    {
        $this->assertDbParity(['email' => 'unique:users,email'], ['email' => 'ada@example.com']);
    }

    public function testUniqueWithIgnoreIdPassesForOwnRecord(): void
    {
        $this->assertDbParity(
            ['email' => 'unique:users,email,1,id'],
            ['email' => 'ada@example.com']
        );
    }

    public function testUniqueWithIgnoreIdFailsForOtherRecord(): void
    {
        $this->assertDbParity(
            ['email' => 'unique:users,email,2,id'],
            ['email' => 'ada@example.com']
        );
    }
}
