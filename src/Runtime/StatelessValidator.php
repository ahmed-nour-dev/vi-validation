<?php

declare(strict_types=1);

namespace Vi\Validation\Runtime;

use Vi\Validation\Execution\ValidationResult;
use Vi\Validation\Execution\ValidatorEngine;
use Vi\Validation\Execution\CompiledSchema;

/**
 * Stateless validator wrapper for use in long-running processes.
 * Ensures no state leaks between requests.
 */
final class StatelessValidator implements RuntimeAwareInterface
{
    private ValidatorEngine $engine;
    private ContextManager $contextManager;

    /** @var array{failFast: bool, maxErrors: int, errorMode: \Vi\Validation\Execution\ErrorMode, messageResolver: ?\Vi\Validation\Messages\MessageResolver, databaseValidator: ?\Vi\Validation\Rules\DatabaseValidatorInterface, passwordHasher: ?\Vi\Validation\Rules\PasswordHasherInterface} */
    private array $baseline;

    public function __construct(
        ?ValidatorEngine $engine = null,
        ?ContextManager $contextManager = null
    ) {
        $this->engine = $engine ?? new ValidatorEngine();
        $this->contextManager = $contextManager ?? new ContextManager();
        $this->baseline = $this->engine->exportSettings();
    }

    public function onWorkerStart(): void
    {
        $this->contextManager->onWorkerStart();
    }

    public function onRequestStart(): void
    {
        $this->contextManager->onRequestStart();
    }

    /**
     * Ends the request: clears request-scoped context and restores the engine's settings
     * (fail-fast, max errors, error mode, message resolver, database validator, password
     * hasher) to what they were when this validator was created, so nothing one borrower
     * changed is visible to the next.
     */
    public function onRequestEnd(): void
    {
        $this->contextManager->onRequestEnd();
        $this->engine->importSettings($this->baseline);
    }

    public function onWorkerStop(): void
    {
        $this->contextManager->onWorkerStop();
    }

    /**
     * Validate data against a compiled schema.
     *
     * @param array<string, mixed> $data
     */
    public function validate(CompiledSchema $schema, array $data): ValidationResult
    {
        // The engine clears all row state itself when validate() returns or throws; request-
        // level state is reset by onRequestEnd() (called by ValidatorPool::release() or the
        // worker adapters), not per row, so request-level settings apply to every row of the
        // request.
        return $this->engine->validate($schema, $data);
    }

    /**
     * Get the context manager.
     */
    public function getContextManager(): ContextManager
    {
        return $this->contextManager;
    }

    /**
     * Get the validator engine.
     */
    public function getEngine(): ValidatorEngine
    {
        return $this->engine;
    }
}
