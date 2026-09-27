<?php

declare(strict_types=1);

namespace Vi\Validation\Runtime;

use Vi\Validation\Execution\ValidationContext;
use Vi\Validation\Execution\ErrorCollector;
use Vi\Validation\Messages\MessageResolver;

/**
 * Manages request-scoped validation context for long-running processes.
 */
final class ContextManager implements RuntimeAwareInterface
{
    private ?ValidationContext $currentContext = null;
    private ?MessageResolver $messageResolver = null;

    /** @var array<string, string> */
    private array $customMessages = [];

    /** @var array<string, string> */
    private array $customAttributes = [];

    public function onWorkerStart(): void
    {
        $this->messageResolver = new MessageResolver();
    }

    public function onRequestStart(): void
    {
        $this->clearRequestState();
    }

    public function onRequestEnd(): void
    {
        $this->clearRequestState();
    }

    private function clearRequestState(): void
    {
        $this->currentContext = null;
        $this->customMessages = [];
        $this->customAttributes = [];
        $this->messageResolver?->setCustomMessages([]);
        $this->messageResolver?->setCustomAttributes([]);
    }

    public function onWorkerStop(): void
    {
        $this->currentContext = null;
        $this->messageResolver = null;
    }

    /**
     * Create a new validation context for the current request.
     *
     * @param array<string, mixed> $data
     */
    public function createContext(array $data): ValidationContext
    {
        $errors = new ErrorCollector();
        $this->currentContext = new ValidationContext($data, $errors);

        return $this->currentContext;
    }

    /**
     * Get the current validation context.
     */
    public function getContext(): ?ValidationContext
    {
        return $this->currentContext;
    }

    /**
     * Get or create the message resolver.
     */
    public function getMessageResolver(): MessageResolver
    {
        if ($this->messageResolver === null) {
            $this->messageResolver = new MessageResolver();
        }

        // Always apply (even when empty): the resolver is shared for the worker's lifetime, so
        // skipping empty sets would leave the previous request's messages/attributes active.
        $this->messageResolver->setCustomMessages($this->customMessages);
        $this->messageResolver->setCustomAttributes($this->customAttributes);

        return $this->messageResolver;
    }

    /**
     * Set custom validation messages for the current request.
     *
     * @param array<string, string> $messages
     */
    public function setCustomMessages(array $messages): void
    {
        $this->customMessages = $messages;
    }

    /**
     * Set custom attribute names for the current request.
     *
     * @param array<string, string> $attributes
     */
    public function setCustomAttributes(array $attributes): void
    {
        $this->customAttributes = $attributes;
    }

    /**
     * Reset all request-scoped state.
     */
    public function reset(): void
    {
        $this->onRequestEnd();
    }
}
