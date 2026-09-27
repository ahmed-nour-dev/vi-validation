<?php

declare(strict_types=1);

namespace Vi\Validation\Laravel;

use Closure;
use Illuminate\Validation\PresenceVerifierInterface;
use Vi\Validation\Rules\DatabaseValidatorInterface;

/**
 * Backs `exists` / `unique` with Laravel's own presence verifier (the `validation.presence`
 * binding, i.e. DatabasePresenceVerifier), so queries, connections and extra where-clauses
 * behave exactly as in Laravel's validator.
 *
 * Accepts either a verifier or a resolver closure; the closure is only called on the first
 * database rule evaluated, so apps that never use exists/unique never touch the database.
 */
final class PresenceVerifierDatabaseValidator implements DatabaseValidatorInterface
{
    private ?PresenceVerifierInterface $verifier = null;

    /**
     * @param PresenceVerifierInterface|Closure(): PresenceVerifierInterface $verifierOrResolver
     */
    public function __construct(private readonly PresenceVerifierInterface|Closure $verifierOrResolver)
    {
    }

    public function exists(string $table, string $column, mixed $value, array $extraConstraints = [], ?string $connection = null): bool
    {
        $verifier = $this->verifier($connection);

        if (is_array($value)) {
            // Laravel: every (distinct) value must exist.
            $values = array_values(array_unique(array_filter($value, 'is_scalar')));

            return $values !== [] && count($values) === count($value)
                && $verifier->getMultiCount($table, $column, $values, $extraConstraints) >= count($values);
        }

        return $verifier->getCount($table, $column, $value, null, null, $extraConstraints) > 0;
    }

    public function unique(string $table, string $column, mixed $value, mixed $ignoreId = null, string $idColumn = 'id', array $extraConstraints = [], ?string $connection = null): bool
    {
        return $this->verifier($connection)->getCount($table, $column, $value, $ignoreId, $idColumn, $extraConstraints) === 0;
    }

    private function verifier(?string $connection): PresenceVerifierInterface
    {
        if ($this->verifier === null) {
            $this->verifier = $this->verifierOrResolver instanceof Closure
                ? ($this->verifierOrResolver)()
                : $this->verifierOrResolver;
        }

        if ($this->verifier instanceof \Illuminate\Validation\DatabasePresenceVerifierInterface) {
            // null selects the default connection, exactly as Laravel's own validator passes it.
            /** @phpstan-ignore-next-line argument.type */
            $this->verifier->setConnection($connection);
        }

        return $this->verifier;
    }
}
