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
     * Cancel a pending promise and all child promises in the chain.
     *
     * Cancelling a promise notifies that there is no further interest in its result
     * and triggers cleanup through registered cancel handlers. This is distinct from
     * rejection - a cancelled promise has a separate cancelled state.
     *
     * **Propagation:**
     * - Forward: Child promises created via then() are automatically cancelled
     * - Backward: NOT supported in normal chains (only in race() and any() and cancelChain())
     * - Settled: Calling cancel() on an already settled promise is a no-op
     *
     * **Important:** Always cancel at the source promise, not at intermediate chain points.
     * Cancelling a child promise does not cancel the parent or underlying operation.
     *
     * ```php
     * // CORRECT: Cancel the source
     * $download = downloadFile($url);
     * $result = $download->then(fn($file) => processFile($file));
     * $download->cancel();  // Cancels download AND processFile chain
     *
     * // WRONG: Cancelling child doesn't stop the download
     * $result = downloadFile($url)->then(fn($file) => processFile($file));
     * $result->cancel();  // Download continues, only child is cancelled
     * ```
     *
     * **Use cases:**
     * - User clicks "Stop" button on long-running operation
     * - Timeout expires and you want to stop related async work
     * - Switching views/pages and need to abort pending requests
     * - Race condition resolved: cancel all losing competitors
     *
     * @return void
     */
    public function cancel(): void;

    /**
     * Cancel the entire promise chain from this point up to the root, then downward to all descendants.
     *
     * This method walks upward through parent promises to find the root, then cancels
     * the entire chain starting from the root. This is useful when you only have a
     * reference to a child promise but need to cancel the entire operation.
     *
     * **Propagation:**
     * - Upward: Walks to the root promise
     * - Downward: Cancels root and all descendants via forward propagation
     * - Settled: Calling cancelChain() on a settled promise is a no-op
     *
     * **Use cases:**
     * - Cancel an operation from any point in the promise chain
     * - Stop a complex multi-step async workflow from an intermediate step
     * - No need to keep a reference to the root promise
     *
     * ```php
     * // Without cancelChain() - must keep root reference
     * $root = downloadFile($url);
     * $processed = $root->then(fn($file) => processFile($file));
     * $root->cancel();  // Must use root, not processed
     *
     * // With cancelChain() - cancel from any point
     * $processed = downloadFile($url)->then(fn($file) => processFile($file));
     * $processed->cancelChain();  // Works from child, cancels entire chain
     * ```
     *
     * **Difference from cancel():**
     * - `cancel()` - Only cancels forward to children (safe default)
     * - `cancelChain()` - Cancels upward to root, then downward to all descendants
     *
     * @return void
     */
    public function cancelChain(): void;

    /**
     * Set a handler to be called when the promise is cancelled.
     * You can register multiple cancel handlers, and they will be called in reverse order of registration.
     *
     * This is useful for cleanup operations like:
     * - Cancelling timers
     * - Aborting HTTP requests
     * - Closing file handles
     * - Releasing resources
     *
     * Note:
     * - Cancel handlers are executed in reverse order of registration (LIFO).
     * - By default, cancel handlers execute synchronously.
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
