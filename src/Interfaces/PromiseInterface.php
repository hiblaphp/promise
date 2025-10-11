<?php

namespace Hibla\Promise\Interfaces;

use LogicException;

/**
 * Represents the eventual result of an asynchronous operation.
 *
 * A Promise is an object representing a value that may not be available yet,
 * but will be resolved at some point in the future.
 *
 * @template TValue The type of the value that the promise will resolve with.
 */
interface PromiseInterface
{
    /**
     * Resolves the promise with a successful value.
     *
     * Once resolved, the promise cannot be resolved or rejected again.
     * All attached fulfillment handlers will be called with the value.
     *
     * @param  TValue  $value  The value to resolve the promise with.
     */
    public function resolve(mixed $value): void;

    /**
     * Rejects the promise with a failure reason.
     *
     * Once rejected, the promise cannot be resolved or rejected again.
     * All attached rejection handlers will be called with the reason.
     *
     * @param  mixed  $reason  The reason for rejection (typically an exception or error message).
     */
    public function reject(mixed $reason): void;

    /**
     * Attaches handlers for promise fulfillment and/or rejection.
     *
     * Returns a new promise that will be resolved or rejected based on
     * the return value of the executed handler. This allows for chaining and
     * transforming values.
     *
     * @template TResult
     *
     * @param  callable(TValue): (TResult|PromiseInterface<TResult>)  $onFulfilled  Handler for successful resolution.
     * @param  callable(mixed): (TResult|PromiseInterface<TResult>)  $onRejected  Handler for rejection.
     * @return PromiseInterface<TResult> A new promise for method chaining.
     */
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): PromiseInterface;

    /**
     * Attaches a handler for promise rejection only.
     *
     * Equivalent to calling then(null, $onRejected).
     *
     * @template TResult
     *
     * @param  callable(mixed): (TResult|PromiseInterface<TResult>)  $onRejected  Handler for rejection.
     * @return PromiseInterface<TResult> A new promise for method chaining.
     */
    public function catch(callable $onRejected): PromiseInterface;

    /**
     * Attaches a handler that executes regardless of promise outcome.
     *
     * The finally handler receives no arguments and its return value
     * does not affect the promise chain unless it throws an exception.
     *
     * @return PromiseInterface<TValue> A new promise that will settle with the same outcome as the original.
     */
    public function finally(callable $onFinally): PromiseInterface;

    /**
     * Checks if the promise has been resolved with a value.
     *
     * @return bool True if resolved, false otherwise.
     */
    public function isResolved(): bool;

    /**
     * Checks if the promise has been rejected with a reason.
     *
     * @return bool True if rejected, false otherwise.
     */
    public function isRejected(): bool;

    /**
     * Checks if the promise is still pending (neither resolved nor rejected).
     *
     * @return bool True if pending, false otherwise.
     */
    public function isPending(): bool;

    /**
     * Gets the resolved value of the promise.
     *
     * This method should only be called after confirming the promise
     * is resolved using isResolved().
     *
     * @return TValue The resolved value.
     *
     * @throws LogicException If called on a non-resolved promise.
     */
    public function getValue(): mixed;

    /**
     * Gets the rejection reason of the promise.
     *
     * This method should only be called after confirming the promise
     * is rejected using isRejected().
     *
     * @return mixed The rejection reason.
     *
     * @throws LogicException If called on a non-rejected promise.
     */
    public function getReason(): mixed;

    /**
     * Get the root cancellable promise in the chain, if any.
     *
     * Note: Typically, only the creator of the CancellablePromise should
     * call cancel(). This method is provided for advanced use cases and
     * debugging. Use with care.
     *
     * @return CancellablePromiseInterface<mixed>|null
     */
    public function getRootCancellable(): ?CancellablePromiseInterface;

    /**
     * **[BLOCKING]** Wait for the promise to resolve synchronously.
     *
     * ⚠️ This method BLOCKS the current thread by running the EventLoop
     * until the promise settles. Use this only at the top-level of your
     * application or in synchronous contexts.
     *
     * For non-blocking async code, use the await() function instead.
     *
     * ```php
     * // ❌ Don't use inside async blocks
     * async(function() {
     *     return $promise->await();  // Blocks unnecessarily!
     * });
     *
     * // ✅ Use await() function instead
     * async(function() {
     *     return await($promise);  // Suspends fiber properly
     * });
     *
     * // ✅ Use ->await() at top-level
     * $result = $promise->await();  // Blocks to get result
     * ```
     *
     * @param  bool  $resetEventLoop  Reset event loop after completion
     * @return TValue
     * @throws \Throwable
     */
    public function await(bool $resetEventLoop = true): mixed;
}
