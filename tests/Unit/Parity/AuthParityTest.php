<?php

declare(strict_types=1);

namespace Vi\Validation\Tests\Unit\Parity;

use Illuminate\Container\Container;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\Rules\Password;
use Vi\Validation\Execution\ErrorCollector;
use Vi\Validation\Execution\ValidationContext;
use Vi\Validation\Rules\CurrentPasswordRule;
use Vi\Validation\Rules\PasswordHasherInterface;
use Vi\Validation\Rules\PasswordRule;
use Vi\Validation\Tests\Unit\Parity\Support\ParityTestCase;

/**
 * Covers RuleId: password, current_password.
 *
 * Neither rule has a plain pipe-string grammar on either side (Laravel's Password is a fluent
 * Rule object; current_password needs a real auth guard + hasher), so these are compared via
 * directly-constructed rule objects/fakes rather than assertParity()'s shared rules array.
 */
class AuthParityTest extends ParityTestCase
{
    public function testPasswordPassesPolicy(): void
    {
        $laravelRules = ['password' => ['required', Password::min(8)->letters()->numbers()]];
        $fastRules = ['password' => ['required', (new PasswordRule())->min(8)->letters()->numbers()]];
        $data = ['password' => 'Secret123'];

        $laravel = $this->laravel($data, $laravelRules);
        $fast = $this->fast($data, $fastRules);

        self::assertSame($laravel->fails(), $fast->fails());
    }

    public function testPasswordFailsTooShort(): void
    {
        $laravelRules = ['password' => ['required', Password::min(8)]];
        $fastRules = ['password' => ['required', (new PasswordRule())->min(8)]];
        $data = ['password' => 'short'];

        $laravel = $this->laravel($data, $laravelRules);
        $fast = $this->fast($data, $fastRules);

        self::assertSame($laravel->fails(), $fast->fails());
        self::assertTrue($fast->fails());
    }

    public function testPasswordFailsMissingNumbers(): void
    {
        $laravelRules = ['password' => ['required', Password::min(8)->numbers()]];
        $fastRules = ['password' => ['required', (new PasswordRule())->min(8)->numbers()]];
        $data = ['password' => 'onlyletters'];

        $laravel = $this->laravel($data, $laravelRules);
        $fast = $this->fast($data, $fastRules);

        self::assertSame($laravel->fails(), $fast->fails());
        self::assertTrue($fast->fails());
    }

    public function testCurrentPasswordPassesForMatchingHash(): void
    {
        $plain = 'correct-horse-battery-staple';
        $hash = password_hash($plain, PASSWORD_BCRYPT);

        $laravelFails = $this->laravelCurrentPasswordFails($plain, $hash);
        $fastFails = $this->fastCurrentPasswordFails($plain, $hash);

        self::assertSame($laravelFails, $fastFails);
        self::assertFalse($fastFails);
    }

    public function testCurrentPasswordFailsForMismatchedHash(): void
    {
        $hash = password_hash('the-real-password', PASSWORD_BCRYPT);

        $laravelFails = $this->laravelCurrentPasswordFails('a-wrong-guess', $hash);
        $fastFails = $this->fastCurrentPasswordFails('a-wrong-guess', $hash);

        self::assertSame($laravelFails, $fastFails);
        self::assertTrue($fastFails);
    }

    private function laravelCurrentPasswordFails(string $submitted, string $storedHash): bool
    {
        $container = new Container();

        $user = new class ($storedHash) {
            public function __construct(private string $hash)
            {
            }

            public function getAuthPassword(): string
            {
                return $this->hash;
            }
        };

        $guard = new class ($user) {
            public function __construct(private object $user)
            {
            }

            public function guest(): bool
            {
                return false;
            }

            public function user(): object
            {
                return $this->user;
            }
        };

        $auth = new class ($guard) {
            public function __construct(private object $guard)
            {
            }

            public function guard(?string $name = null): object
            {
                return $this->guard;
            }
        };

        $hasher = new class () {
            public function check(string $value, string $hashedValue): bool
            {
                return password_verify($value, $hashedValue);
            }
        };

        $container->instance('auth', $auth);
        $container->instance('hash', $hasher);

        $translator = new Translator(new ArrayLoader(), 'en');
        $factory = new Factory($translator, $container);
        $validator = $factory->make(['password' => $submitted], ['password' => 'current_password']);

        return $validator->fails();
    }

    private function fastCurrentPasswordFails(string $submitted, string $storedHash): bool
    {
        $hasher = new class ($storedHash) implements PasswordHasherInterface {
            public function __construct(private string $hash)
            {
            }

            public function check(string $password): bool
            {
                return password_verify($password, $this->hash);
            }
        };

        $context = new ValidationContext(['password' => $submitted], new ErrorCollector());
        $context->setPasswordHasher($hasher);

        $rule = new CurrentPasswordRule();
        $error = $rule->validate($submitted, 'password', $context);

        return $error !== null;
    }
}
