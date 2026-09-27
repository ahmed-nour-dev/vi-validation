<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Vi\Validation\Execution\CompiledSchema;
use Vi\Validation\Execution\ErrorMode;
use Vi\Validation\Execution\ValidationContext;
use Vi\Validation\Execution\ValidationResult;
use Vi\Validation\Execution\ValidatorEngine;
use Vi\Validation\Laravel\FastValidatorFactory;
use Vi\Validation\Messages\MessageResolver;
use Vi\Validation\Messages\Translator;
use Vi\Validation\Rules\DatabaseValidatorInterface;
use Vi\Validation\Rules\ExistsRule;
use Vi\Validation\Rules\PasswordHasherInterface;
use Vi\Validation\Runtime\ContextManager;
use Vi\Validation\Runtime\StatelessValidator;
use Vi\Validation\Runtime\ValidatorPool;
use Vi\Validation\Runtime\Workers\RoadRunnerAdapter;
use Vi\Validation\Runtime\Workers\SwooleAdapter;
use Vi\Validation\SchemaValidator;
use Vi\Validation\Validator;

/**
 * Long-running worker safety: nothing from one validation / request may be observable in the
 * next, whatever happened in between.
 */
final class WorkerSafetyTest extends TestCase
{
    private function userSchema(): CompiledSchema
    {
        return Validator::fromRules([
            'name' => 'required|string|max:10',
            'email' => 'required|email',
            'age' => 'nullable|integer|min:18',
            'nick' => 'sometimes|required|alpha',
        ]);
    }

    private function orderSchema(): CompiledSchema
    {
        return Validator::fromRules(['sku' => 'required|alpha_dash', 'qty' => 'required|integer|min:1']);
    }

    /**
     * @return array<string, list<string>>
     */
    private static function rules(ValidationResult $result): array
    {
        return array_map(static fn (array $errors): array => array_column($errors, 'rule'), $result->errors());
    }

    public function testThousandsOfInterleavedValidationsMatchFreshEngines(): void
    {
        $shared = new ValidatorEngine();
        $schemas = [$this->userSchema(), $this->orderSchema()];
        $rows = [
            ['name' => 'Ann', 'email' => 'ann@example.com'],
            ['name' => str_repeat('x', 20), 'email' => 'nope', 'age' => 3, 'nick' => '12'],
            ['name' => 'Bob', 'email' => 'bob@example.com', 'age' => null],
            ['sku' => 'A-1', 'qty' => 2],
            ['sku' => 'bad sku!', 'qty' => 0],
            [],
        ];

        mt_srand(10);
        for ($i = 0; $i < 3000; $i++) {
            $schema = $schemas[mt_rand(0, 1)];
            $row = $rows[mt_rand(0, count($rows) - 1)];

            $expected = (new ValidatorEngine())->validate($schema, $row);
            $actual = $shared->validate($schema, $row);

            $this->assertSame($expected->errors(), $actual->errors(), "iteration {$i}");
            $this->assertSame($expected->validated(), $actual->validated(), "iteration {$i}");
        }
    }

    public function testResultsStayImmutableAfterLaterValidations(): void
    {
        $engine = new ValidatorEngine();
        $invalid = $engine->validate($this->userSchema(), ['name' => '', 'email' => 'x']);
        $snapshot = [$invalid->errors(), $invalid->data(), $invalid->messages()];

        for ($i = 0; $i < 50; $i++) {
            $engine->validate($this->userSchema(), ['name' => 'Ok', 'email' => 'ok@example.com']);
            $engine->validate($this->orderSchema(), ['sku' => '!!!']);
        }

        $this->assertSame($snapshot, [$invalid->errors(), $invalid->data(), $invalid->messages()]);
    }

    public function testEngineRetainsNoRowDataAfterValidation(): void
    {
        $engine = new ValidatorEngine();
        $engine->validate($this->userSchema(), ['name' => 'secret-payload', 'email' => 'x']);

        $this->assertSame([], $this->internalContext($engine)?->getData(), 'last row must not stay reachable');
        $this->assertSame([], $this->internalErrors($engine));
    }

    public function testExceptionInsideARuleLeavesNoPartialState(): void
    {
        $engine = new ValidatorEngine();
        $schema = Validator::schema()
            ->field('a')->required()->email()
            ->field('b')->rules(new \Vi\Validation\Rules\ClosureRule(static function (): void {
                throw new \RuntimeException('boom');
            }))
            ->compile();

        try {
            $engine->validate($schema, ['a' => 'not-an-email', 'b' => 'secret']);
            $this->fail('expected exception');
        } catch (\RuntimeException) {
        }

        $this->assertSame([], $this->internalErrors($engine), 'partial errors of the failed row were kept');
        $this->assertSame([], $this->internalContext($engine)?->getData());

        $clean = $engine->validate($this->orderSchema(), ['sku' => 'A1', 'qty' => 1]);
        $this->assertTrue($clean->isValid());
    }

    public function testDatabaseValidatorAndHasherChangesTakeEffectImmediately(): void
    {
        $engine = new ValidatorEngine();
        $schema = Validator::schema()->field('id')->rules(new ExistsRule('users', 'id'))->compile();

        $engine->setDatabaseValidator(new FakeDatabaseValidator(false));
        $this->assertFalse($engine->validate($schema, ['id' => 1])->isValid());

        $engine->setDatabaseValidator(new FakeDatabaseValidator(true));
        $this->assertTrue($engine->validate($schema, ['id' => 1])->isValid());

        $engine->setDatabaseValidator(null);
        $this->assertNull($this->internalContextDb($engine));

        $current = Validator::fromRules(['password' => 'current_password']);
        $engine->setPasswordHasher(new FakeHasher(false));
        $this->assertFalse($engine->validate($current, ['password' => 'x'])->isValid());
        $engine->setPasswordHasher(new FakeHasher(true));
        $this->assertTrue($engine->validate($current, ['password' => 'x'])->isValid());
    }

    public function testFactoryMessagesAndAttributesNeverBleedBetweenCalls(): void
    {
        $factory = new FastValidatorFactory();

        $custom = $factory->make(['email' => 'x'], ['email' => 'email'], ['email.email' => 'CUSTOM :attribute'], ['email' => 'mail']);
        $this->assertSame('CUSTOM mail', $custom->errors()->first('email'));

        $plain = $factory->make(['email' => 'x'], ['email' => 'email']);
        $this->assertStringNotContainsString('CUSTOM', (string) $plain->errors()->first('email'));
        $this->assertStringContainsString('email', (string) $plain->errors()->first('email'));

        $arabic = new FastValidatorFactory(['localization' => ['locale' => 'ar']]);
        $this->assertNotSame(
            $plain->errors()->first('email'),
            $arabic->make(['email' => 'x'], ['email' => 'email'])->errors()->first('email')
        );
        $this->assertSame($plain->errors()->first('email'), $factory->make(['email' => 'x'], ['email' => 'email'])->errors()->first('email'));
    }

    public function testFailFastOnOneWrapperDoesNotAffectTheNext(): void
    {
        $factory = new FastValidatorFactory();
        $rules = ['a' => 'required', 'b' => 'required'];

        $this->assertCount(1, $factory->make([], $rules)->stopOnFirstFailure()->errors()->toArray());
        $this->assertCount(2, $factory->make([], $rules)->errors()->toArray());
    }

    public function testContextManagerCustomMessagesDoNotLeakIntoTheNextRequest(): void
    {
        $manager = new ContextManager();
        $manager->onWorkerStart();

        $manager->onRequestStart();
        $manager->setCustomMessages(['required' => 'REQUEST A']);
        $manager->setCustomAttributes(['email' => 'A-mail']);
        $this->assertSame('REQUEST A', $manager->getMessageResolver()->resolve('email', 'required'));
        $manager->onRequestEnd();

        $manager->onRequestStart();
        $message = $manager->getMessageResolver()->resolve('email', 'required');
        $this->assertStringNotContainsString('REQUEST A', $message);
        $this->assertStringNotContainsString('A-mail', $message);
    }

    public function testPooledValidatorSettingsAreRestoredOnRelease(): void
    {
        $pool = new ValidatorPool(1);
        $pool->onWorkerStart();
        $schema = Validator::fromRules(['a' => 'required|email', 'b' => 'required']);

        $first = $pool->acquire();
        $engine = $first->getEngine();
        $engine->setFailFast(true);
        $engine->setMaxErrors(1);
        $engine->setErrorMode(ErrorMode::CountOnly);
        $engine->setDatabaseValidator(new FakeDatabaseValidator(true));
        $engine->setPasswordHasher(new FakeHasher(true));
        $engine->setMessageResolver(new MessageResolver(new Translator('ar')));
        $this->assertSame(1, $first->validate($schema, [])->errorCount());
        $pool->release($first);

        $second = $pool->acquire();
        $this->assertSame($first, $second, 'pool of one reuses the instance');
        $settings = $second->getEngine()->exportSettings();
        $this->assertFalse($settings['failFast']);
        $this->assertSame(100, $settings['maxErrors']);
        $this->assertSame(ErrorMode::All, $settings['errorMode']);
        $this->assertNull($settings['databaseValidator']);
        $this->assertNull($settings['passwordHasher']);
        $this->assertSame(2, count($second->validate($schema, [])->errors()));
        $pool->release($second);
    }

    public function testPoolAcquireReleaseUnderExceptions(): void
    {
        $pool = new ValidatorPool(2);
        $pool->onWorkerStart();
        $before = $pool->getPoolSize();

        for ($i = 0; $i < 10; $i++) {
            try {
                $pool->withValidator(static function (StatelessValidator $v): void {
                    $v->getEngine()->setFailFast(true);
                    throw new \LogicException('request failed');
                });
            } catch (\LogicException) {
            }
        }

        $this->assertSame($before, $pool->getPoolSize(), 'validators return to the pool after exceptions');
        $this->assertSame(0, $pool->getCheckedOutCount());
        $this->assertFalse($pool->withValidator(static fn (StatelessValidator $v): bool => $v->getEngine()->isFailFast()));
    }

    public function testDoubleReleaseCannotShareAValidatorBetweenBorrowers(): void
    {
        $pool = new ValidatorPool(3);

        $a = $pool->acquire();
        $pool->release($a);
        $pool->release($a); // double release must be a no-op

        $x = $pool->acquire();
        $y = $pool->acquire();
        $this->assertNotSame($x, $y, 'one instance handed to two concurrent borrowers');

        $pool->release(new StatelessValidator()); // foreign validator: ignored
        $this->assertSame(2, $pool->getCheckedOutCount());
    }

    public function testExhaustedPoolHandsOutTemporaryValidatorsThatAreNotRetained(): void
    {
        $pool = new ValidatorPool(2);
        $held = [$pool->acquire(), $pool->acquire()];
        $temporary = $pool->acquire();

        $this->assertNotContains($temporary, $held);
        $this->assertSame(2, $pool->getCreatedCount());

        $pool->release($temporary);
        foreach ($held as $v) {
            $pool->release($v);
        }

        $this->assertSame(2, $pool->getPoolSize(), 'pool never grows beyond its size');
        $this->assertSame(0, $pool->getCheckedOutCount());
    }

    public function testRoadRunnerAndSwooleLifecyclesStayCleanAcrossManyRequests(): void
    {
        $schema = $this->userSchema();

        foreach ([new RoadRunnerAdapter(new ValidatorPool(2)), new SwooleAdapter(new ValidatorPool(2))] as $adapter) {
            $adapter->onWorkerStart();
            $pool = $adapter->getPool();

            for ($request = 0; $request < 200; $request++) {
                $adapter->onRequestStart();
                $validator = $pool->acquire();
                try {
                    if ($request % 3 === 0) {
                        $validator->getEngine()->setFailFast(true);
                    }
                    $row = $request % 2 === 0 ? ['name' => 'Ann', 'email' => 'a@example.com'] : ['email' => 'x'];
                    $result = $validator->validate($schema, $row);

                    $expected = (new ValidatorEngine(null, $request % 3 === 0))->validate($schema, $row);
                    $this->assertSame($expected->errors(), $result->errors(), get_class($adapter) . " request {$request}");
                } finally {
                    $pool->release($validator);
                    $adapter->onRequestEnd();
                }
            }

            $this->assertSame(0, $pool->getCheckedOutCount());
            $this->assertLessThanOrEqual(2, $pool->getPoolSize());
            $adapter->onWorkerStop();
            $this->assertSame(0, $pool->getPoolSize());
        }
    }

    public function testSharedSchemaValidatorAcrossRequestsWithDifferentOptions(): void
    {
        $validator = new SchemaValidator($this->userSchema());

        $validator->setErrorMode(ErrorMode::FirstPerRow);
        $this->assertSame(1, $validator->validate(['email' => 'x', 'age' => 1])->errorCount());

        $validator->setErrorMode(ErrorMode::All);
        $this->assertSame(3, $validator->validate(['email' => 'x', 'age' => 1])->errorCount());
    }

    private function internalContext(ValidatorEngine $engine): ?ValidationContext
    {
        $property = new \ReflectionProperty(ValidatorEngine::class, 'context');
        $property->setAccessible(true);

        /** @var ValidationContext|null */
        return $property->getValue($engine);
    }

    /**
     * @return array<string, mixed>
     */
    private function internalErrors(ValidatorEngine $engine): array
    {
        $property = new \ReflectionProperty(ValidatorEngine::class, 'errors');
        $property->setAccessible(true);
        $collector = $property->getValue($engine);

        return $collector === null ? [] : $collector->all();
    }

    private function internalContextDb(ValidatorEngine $engine): ?DatabaseValidatorInterface
    {
        $engine->validate($this->orderSchema(), []);

        return $this->internalContext($engine)?->getDatabaseValidator();
    }
}

final class FakeDatabaseValidator implements DatabaseValidatorInterface
{
    public function __construct(private readonly bool $answer)
    {
    }

    public function exists(string $table, string $column, mixed $value, array $extraConstraints = [], ?string $connection = null): bool
    {
        return $this->answer;
    }

    public function unique(string $table, string $column, mixed $value, mixed $ignoreId = null, string $idColumn = 'id', array $extraConstraints = [], ?string $connection = null): bool
    {
        return $this->answer;
    }
}

final class FakeHasher implements PasswordHasherInterface
{
    public function __construct(private readonly bool $answer)
    {
    }

    public function check(string $password): bool
    {
        return $this->answer;
    }
}
