<?php

declare(strict_types=1);

namespace Vi\Validation\Execution;

use Vi\Validation\Messages\MessageResolver;

/**
 * NativeValidator executes a precompiled PHP closure for maximum performance.
 */
final class NativeValidator
{
    /** @var \Closure(array<string, mixed>): array{errors: array<string, mixed>, excluded_fields: list<string>} */
    private \Closure $closure;
    private ?MessageResolver $messageResolver;

    /**
     * @param \Closure(array<string, mixed>): array{errors: array<string, mixed>, excluded_fields: list<string>} $closure
     * @param MessageResolver|null $messageResolver
     */
    public function __construct(\Closure $closure, ?MessageResolver $messageResolver = null)
    {
        $this->closure = $closure;
        $this->messageResolver = $messageResolver ?? new MessageResolver();
    }

    /**
     * Validate the given data using the precompiled closure.
     *
     * @param array<string, mixed> $data
     */
    public function validate(array $data): ValidationResult
    {
        $result = ($this->closure)($data);

        return new ValidationResult(
            $result['errors'],
            $data,
            $this->messageResolver,
            $result['excluded_fields'] ?? []
        );
    }
}
