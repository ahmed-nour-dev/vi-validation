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
use Vi\Validation\Tests\Unit\Parity\Support\ParityTestCase;

/**
 * Covers RuleId: exists, unique.
 *
 * Runs through the real Laravel integration surface: FastValidatorFactory with Laravel's own
 * DatabasePresenceVerifier (via PresenceVerifierDatabaseValidator), which is exactly what
 * FastValidationServiceProvider wires up. (Before, the factory never had a database validator
 * and exists/unique silently passed.)
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
        $factory = new \Vi\Validation\Laravel\FastValidatorFactory();
        $factory->setDatabaseValidator(new \Vi\Validation\Laravel\PresenceVerifierDatabaseValidator(
            new DatabasePresenceVerifier($this->capsule()->getDatabaseManager())
        ));

        $wrapper = $factory->make($data, $rules);
        $wrapper->passes();

        return $wrapper->getSchemaValidator()->validate($data);
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

    public function testRuleObjectsForExistsAndUnique(): void
    {
        $this->assertDbParity(['email' => [\Illuminate\Validation\Rule::unique('users')->ignore(1)]], ['email' => 'ada@example.com']);
        $this->assertDbParity(['email' => [\Illuminate\Validation\Rule::unique('users')->ignore(2)]], ['email' => 'ada@example.com']);
        $this->assertDbParity(['email' => [\Illuminate\Validation\Rule::exists('users')->where('status', 'active')]], ['email' => 'ada@example.com']);
        $this->assertDbParity(['email' => [\Illuminate\Validation\Rule::exists('users')->where('status', 'active')]], ['email' => 'grace@example.com']);
    }

    public function testExistsWithArrayValues(): void
    {
        $this->assertDbParity(['ids' => 'array|exists:users,id'], ['ids' => [1, 2]]);
        $this->assertDbParity(['ids' => 'array|exists:users,id'], ['ids' => [1, 99]]);
    }

    public function testDatabaseRulesFailClosedWithoutADatabaseValidator(): void
    {
        foreach (['exists:users,email', 'unique:users,email'] as $rule) {
            try {
                (new \Vi\Validation\Laravel\FastValidatorFactory())->make(['email' => 'x@example.com'], ['email' => $rule])->passes();
                self::fail("{$rule} must not silently pass without a database validator");
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('requires a database validator', $e->getMessage());
            }
        }
    }
}
