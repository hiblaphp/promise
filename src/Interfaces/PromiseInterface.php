<?php

declare(strict_types=1);

namespace Hibla\Promise\Interfaces;

/**
 * Represents the eventual result of an asynchronous operation.
 *
 * A Promise is an object representing a value that may not be available yet,
 * but will be resolved at some point in the future.
 *
 * All promises are cancellable. Cancelling a settled promise is a no-op.
 * Cancellation propagates forward through promise chains (child promises are
 * cancelled when parent is cancelled).
 *
 * @template TValue The type of the value that the promise will resolve with.
 */
interface PromiseInterface
{
    /**
     * Attaches handlers for promise fulfillment and/or rejection.
     *
     * Returns a new promise that will be resolved or rejected based on
     * the return value of the executed handler. This allows for chaining and
     * transforming values.
     *
     * A promise makes the following guarantees about handlers registered in
     * the same call to then():
     *
     *  1. Only one of $onFulfilled or $onRejected will be called, never both.
     *  2. $onFulfilled and $onRejected will never be called more than once.
     *
     * @template TResult
     *
     * @param  callable(TValue): (TResult|PromiseInterface<TResult>)|null  $onFulfilled  Handler for successful resolution.
     * @param  callable(mixed): (TResult|PromiseInterface<TResult>)|null  $onRejected  Handler for rejection.
     * @return PromiseInterface<TResult> A new promise for method chaining.
     */
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): PromiseInterface;

    /**
     * Attaches a handler for promise rejection only.
     *
     * Equivalent to calling then(null, $onRejected).
     *
     * Additionally, you can type hint the $reason argument of $onRejected to catch
     * only specific error types.
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
     * finally() behaves similarly to the synchronous finally statement. When combined
     * with catch(), finally() allows you to write code that is similar to the familiar
     * synchronous catch/finally pair.
     *
     * * If $promise fulfills, and $onFulfilledOrRejected returns successfully,
     *   the returned promise will fulfill with the same value as $promise.
     * * If $promise fulfills, and $onFulfilledOrRejected throws or returns a
     *   rejected promise, the returned promise will reject with the thrown exception or
     *   rejected promise's reason.
     * * If $promise rejects, and $onFulfilledOrRejected returns successfully,
     *   the returned promise will reject with the same reason as $promise.
     * * If $promise rejects, and $onFulfilledOrRejected throws or returns a
     *   rejected promise, the returned promise will reject with the thrown exception or
     *   rejected promise's reason.
     *
     * @param callable(): (void|PromiseInterface<void>) $onFulfilledOrRejected
     * @return PromiseInterface<TValue> A new promise that will settle with the same outcome as the original.
     */
    public function finally(callable $onFulfilledOrRejected): PromiseInterface;

    /**
     * The cancel() method notifies the creator of the promise that there is no
     * further interest in the results of the operation.
     *
     * Backward propagation is not supported and only allowed in Promise::race() and Promise::any().
     *
     * Once a promise is settled (either fulfilled or rejected), calling cancel() on
     * a promise has no effect (no-op).
     *
     * Cancelling a pending promise will:
     * - Mark the promise as cancelled
     * - Execute any registered cancel handlers (for cleanup)
     * - Cancel all child promises in the chain (forward propagation)
     * - Reject the promise with a cancellation exception
     *
     * Example use cases:
     * ```php
     * // Cancel a timeout
     * $promise = Promise::timeout($operation, 5.0);
     * $promise->cancel();  // Cancels timer and operation
     *
     * // Cancel HTTP request
     * $promise = $http->get('https://api.example.com');
     * $promise->cancel();  // Aborts the HTTP request
     *
     * // Cancel all losing promises in a race
     * $winner = Promise::race([$promise1, $promise2, $promise3]);
     * // When one wins, others are automatically cancelled
     * ```
     *
     * @return void
     */
    public function cancel(): void;

    /**
     * Set a handler to be called when the promise is cancelled.
     *
     * This is useful for cleanup operations like:
     * - Cancelling timers
     * - Aborting HTTP requests
     * - Closing file handles
     * - Releasing resources
     *
     * @param callable $handler The cleanup handler to execute on cancellation
     * @return void
     */
    public function setCancelHandler(callable $handler): void;

    /**
     * Check if the promise has been cancelled.
     *
     * A cancelled promise will also be rejected, but this method allows you
     * to distinguish between a regular rejection and a cancellation.
     *
     * @return bool True if cancel() was called on this promise, false otherwise.
     */
    public function isCancelled(): bool;

    /**
     * Checks if the promise has been settled (resolved or rejected).
     *
     * A settled promise is one that is no longer pending - it has either
     * been fulfilled with a value or rejected with a reason.
     *
     * @return bool True if the promise is settled, false otherwise.
     */
    public function isSettled(): bool;

    /**
     * Checks if the promise has been resolved with a value.
     *
     * @return bool True if resolved, false otherwise.
     */
    public function isResolved(): bool;

    /**
     * Checks if the promise has been rejected with a reason.
     *
     * Note: A cancelled promise will also be rejected.
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
     * Calling this method marks the value as accessed for unhandled
     * rejection tracking purposes.
     *
     * @return TValue|null The resolved value, or null if not resolved.
     */
    public function getValue(): mixed;

    /**
     * Gets the rejection reason of the promise.
     *
     * This method should only be called after confirming the promise
     * is rejected using isRejected().
     *
     * Calling this method marks the reason as accessed for unhandled
     * rejection tracking purposes.
     *
     * @return mixed The rejection reason, or null if not rejected.
     */
    public function getReason(): mixed;

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
     * If the promise is rejected, this method will throw the rejection reason.
     * If the promise is resolved, this method will return the resolved value.
     *
     * @param  bool  $resetEventLoop  Reset event loop after completion (default: false)
     * @return TValue The resolved value
     * @throws \Throwable If the promise is rejected
     */
    public function await(bool $resetEventLoop = false): mixed;
}
