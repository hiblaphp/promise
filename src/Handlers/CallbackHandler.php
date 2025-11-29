<?php

namespace Hibla\Promise\Handlers;

/**
 * Manages callback registration and execution for Promise instances.
 *
 * This handler maintains collections of callbacks for different Promise states
 * (then, catch, finally) and provides methods to execute them when appropriate.
 */
final class CallbackHandler
{
    /**
     * @var array<callable> Callbacks to execute when Promise resolves
     */
    private array $thenCallbacks = [];

    /**
     * @var array<callable> Callbacks to execute when Promise rejects
     */
    private array $catchCallbacks = [];

    /**
     * @var array<callable> Callbacks to execute when Promise settles (resolve or reject)
     */
    private array $finallyCallbacks = [];

    /**
     * Register a callback to execute when the Promise resolves.
     *
     * @param  callable  $callback  Function to call with the resolved value
     */
    public function addThenCallback(callable $callback): void
    {
        $this->thenCallbacks[] = $callback;
    }

    /**
     * Register a callback to execute when the Promise rejects.
     *
     * @param  callable  $callback  Function to call with the rejection reason
     */
    public function addCatchCallback(callable $callback): void
    {
        $this->catchCallbacks[] = $callback;
    }

    /**
     * Register a callback to execute when the Promise settles (resolves or rejects).
     *
     * @param  callable  $callback  Function to call regardless of Promise outcome
     */
    public function addFinallyCallback(callable $callback): void
    {
        $this->finallyCallbacks[] = $callback;
    }

    /**
     * Execute all registered then callbacks with the resolved value.
     * Clears callbacks immediately after execution to free memory.
     */
    public function executeThenCallbacks(mixed $value): void
    {
        $callbacks = $this->thenCallbacks;
        $this->thenCallbacks = [];

        foreach ($callbacks as $callback) {
            $callback($value);
        }
    }

    /**
     * Execute all registered catch callbacks with the rejection reason.
     * Clears callbacks immediately after execution to free memory.
     */
    public function executeCatchCallbacks(mixed $reason): void
    {
        $callbacks = $this->catchCallbacks;
        $this->catchCallbacks = [];

        foreach ($callbacks as $callback) {
            $callback($reason);
        }
    }

    /**
     * Execute all registered finally callbacks.
     * Clears callbacks immediately after execution to free memory.
     */
    public function executeFinallyCallbacks(): void
    {
        $callbacks = $this->finallyCallbacks;
        $this->finallyCallbacks = [];

        foreach ($callbacks as $callback) {
            $callback();
        }
    }
}
