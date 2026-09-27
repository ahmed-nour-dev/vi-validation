<?php

declare(strict_types=1);

namespace Vi\Validation\Runtime;

use SplQueue;

/**
 * Pool of validator instances for reuse in long-running processes.
 */
final class ValidatorPool implements RuntimeAwareInterface
{
    /** @var SplQueue<StatelessValidator> */
    private SplQueue $pool;

    private int $maxSize;
    private int $created = 0;

    /**
     * Validators currently handed out. Weak, so a borrower that drops a validator without
     * releasing it doesn't leak it (and object-id reuse can never confuse the bookkeeping).
     *
     * @var \WeakMap<StatelessValidator, true>
     */
    private \WeakMap $checkedOut;

    /** @var \WeakMap<StatelessValidator, true> validators owned by (and returnable to) the pool */
    private \WeakMap $pooled;

    public function __construct(int $maxSize = 10)
    {
        $this->maxSize = $maxSize;
        $this->pool = new SplQueue();
        $this->checkedOut = new \WeakMap();
        $this->pooled = new \WeakMap();
    }

    public function onWorkerStart(): void
    {
        // Pre-warm the pool with some validators
        $warmCount = min(3, $this->maxSize);
        for ($i = 0; $i < $warmCount; $i++) {
            $validator = $this->createValidator();
            $validator->onWorkerStart();
            $this->pool->enqueue($validator);
        }
    }

    public function onRequestStart(): void
    {
        // Nothing to do at request start
    }

    public function onRequestEnd(): void
    {
        // Nothing to do at request end
    }

    public function onWorkerStop(): void
    {
        while (!$this->pool->isEmpty()) {
            $validator = $this->pool->dequeue();
            $validator->onWorkerStop();
        }
        $this->created = 0;
        $this->checkedOut = new \WeakMap();
        $this->pooled = new \WeakMap();
    }

    /**
     * Acquire a validator from the pool.
     *
     * A validator is handed to exactly one borrower at a time (instances are not safe for
     * concurrent use, e.g. by two Swoole coroutines). When all pooled validators are checked
     * out, a temporary one is created and discarded on release.
     */
    public function acquire(): StatelessValidator
    {
        if (!$this->pool->isEmpty()) {
            $validator = $this->pool->dequeue();
        } elseif ($this->created < $this->maxSize) {
            $validator = $this->createValidator();
            $validator->onWorkerStart();
        } else {
            // Pool exhausted: temporary validator, never enqueued.
            $validator = new StatelessValidator();
        }

        $this->checkedOut[$validator] = true;
        $validator->onRequestStart();

        return $validator;
    }

    /**
     * Release a validator back to the pool, resetting its request state first.
     *
     * Releasing a validator that isn't currently checked out (a double release, or one this
     * pool never handed out) is a no-op, so it can never be enqueued twice and end up shared
     * by two borrowers.
     */
    public function release(StatelessValidator $validator): void
    {
        if (!isset($this->checkedOut[$validator])) {
            return;
        }
        unset($this->checkedOut[$validator]);

        $validator->onRequestEnd();

        if (isset($this->pooled[$validator]) && $this->pool->count() < $this->maxSize) {
            $this->pool->enqueue($validator);
        }
    }

    /**
     * Number of validators currently handed out and not yet released.
     */
    public function getCheckedOutCount(): int
    {
        return count($this->checkedOut);
    }

    /**
     * Execute validation with automatic acquire/release.
     *
     * @template T
     * @param callable(StatelessValidator): T $callback
     * @return T
     */
    public function withValidator(callable $callback): mixed
    {
        $validator = $this->acquire();

        try {
            return $callback($validator);
        } finally {
            $this->release($validator);
        }
    }

    /**
     * Get the current pool size.
     */
    public function getPoolSize(): int
    {
        return $this->pool->count();
    }

    /**
     * Get the maximum pool size.
     */
    public function getMaxSize(): int
    {
        return $this->maxSize;
    }

    /**
     * Get the total number of validators created.
     */
    public function getCreatedCount(): int
    {
        return $this->created;
    }

    private function createValidator(): StatelessValidator
    {
        $this->created++;
        $validator = new StatelessValidator();
        $this->pooled[$validator] = true;

        return $validator;
    }
}
