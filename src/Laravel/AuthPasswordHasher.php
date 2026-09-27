<?php

declare(strict_types=1);

namespace Vi\Validation\Laravel;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Hashing\Hasher;
use Vi\Validation\Rules\PasswordHasherInterface;

/**
 * Backs `current_password` with Laravel's auth + hasher, like Laravel's validator: the value
 * must match the authenticated user's password on the default guard, and a guest never
 * passes.
 */
final class AuthPasswordHasher implements PasswordHasherInterface
{
    /**
     * @param Closure(): AuthFactory $auth
     * @param Closure(): Hasher $hasher
     */
    public function __construct(private readonly Closure $auth, private readonly Closure $hasher)
    {
    }

    public function check(string $password): bool
    {
        $user = ($this->auth)()->guard()->user();

        if ($user === null) {
            return false;
        }

        return ($this->hasher)()->check($password, $user->getAuthPassword());
    }
}
