<?php

declare(strict_types=1);

namespace Hibla\Promise\Interfaces;

use Hibla\Promise\SettledResult;

/**
 * Interface for promise collection operations.
 *
 * This interface defines static methods for working with collections of promises,
 * including operations like waiting for all promises, racing promises, and
 * managing concurrent execution with limits.
 *
 * !! IMPORTANT: All methods in this interface are STATIC methods.
 * They must be called on the Promise class, NOT on promise instances.
 *
 * ```php
 * // ✓ Correct usage:
 * Promise::all([$promise1, $promise2]);
 * Promise::race($generator);
 * Promise::resolved('value');
 *
 * // ✗ Wrong - do not call on instances:
 * $promise->all([$promise1, $promise2]);
 * $promise->race([$promise1, $promise2]);
 * $promise->resolved('value');
 * !!!! DO NOT CALL STATIC METHODS ON PROMISE INSTANCES !!!!
 * ```
 */
interface PromiseStaticInterface
{
    /**
     * Create a resolved promise with the given value.
     *
     * !! STATIC METHOD - Must be called as Promise::resolved(), NOT $promise->resolved()
     *
     * @template TResolveValue
     *
     * @param  TResolveValue  $value  The value to resolve the promise with
     * @return PromiseInterface<TResolveValue> A promise resolved with the provided value
     *
     * @static
     */
    public static function resolved(mixed $value): PromiseInterface;

    /**
     * Create a rejected promise with the given reason.
     *
     * !! STATIC METHOD - Must be called as Promise::rejected(), NOT $promise->rejected()
     *
     * @param  mixed  $reason  The reason for rejection (typically an exception)
     * @return PromiseInterface<never> A promise rejected with the provided reason
     *
     * @static
     */
    public static function rejected(mixed $reason): PromiseInterface;

    /**
     * Wait for all promises to resolve and return their results.
     *
     * If any promise rejects, the returned promise rejects immediately with
     * the first rejection reason and all remaining promises are automatically
     * cancelled. This enforces structured concurrency semantics — callers
     * should only think about getting results or handling failure, not about
     * the lifecycle of individual promises.
     *
     * For scenarios where you need all outcomes regardless of failure, use
     * Promise::allSettled() instead.
     *
     * !! STATIC METHOD - Must be called as Promise::all(), NOT $promise->all()
     *
     * @template TAllValue
     * @param  iterable<int|string, PromiseInterface<TAllValue>>  $promises  Iterable of PromiseInterface instances.
     * @return PromiseInterface<array<int|string, TAllValue>>                A promise that resolves with an array of results.
     *
     * @static
     */
    public static function all(iterable $promises): PromiseInterface;

    /**
     * Wait for all promises to settle (either resolve or reject).
     *
     * Unlike all(), this method waits for every promise to complete and returns
     * all results, including both successful values and rejection reasons.
     * This method never rejects - it always resolves with an array of settlement results.
     *
     * !! STATIC METHOD - Must be called as Promise::allSettled(), NOT $promise->allSettled()
     *
     * @template TAllSettledValue
     * @param  iterable<int|string, PromiseInterface<TAllSettledValue>>  $promises
     * @return PromiseInterface<array<int|string, SettledResultInterface<TAllSettledValue, mixed>>>
     *
     * @static
     */
    public static function allSettled(iterable $promises): PromiseInterface;

    /**
     * Wait for the first promise to resolve or reject.
     *
     * Returns a promise that settles with the same value/reason as
     * the first promise to settle. Once a promise settles, all other
     * promises that are instances of CancellablePromise will be automatically
     * cancelled to free up resources.
     *
     * !! STATIC METHOD - Must be called as Promise::race(), NOT $promise->race()
     *
     * @template TRaceValue
     * @param  iterable<int|string, PromiseInterface<TRaceValue>>  $promises  Iterable of PromiseInterface instances.
     * @return PromiseInterface<TRaceValue> A promise that settles with the first settled promise.
     *
     * @static
     */
    public static function race(iterable $promises): PromiseInterface;

    /**
     * Wait for any promise in the collection to resolve.
     *
     * Returns a promise that resolves with the value of the first
     * promise that resolves, or rejects if all promises reject.
     * Once a promise resolves, all other promises that are instances
     * of CancellablePromise will be automatically cancelled to free up resources.
     *
     * !! STATIC METHOD - Must be called as Promise::any(), NOT $promise->any()
     *
     * @template TAnyValue
     * @param  iterable<int|string, PromiseInterface<TAnyValue>>  $promises  Iterable of promises to wait for
     * @return PromiseInterface<TAnyValue> A promise that resolves with the first settled value
     *
     * @static
     */
    public static function any(iterable $promises): PromiseInterface;

    /**
     * Create a promise that resolves or rejects with a timeout.
     *
     * !! STATIC METHOD - Must be called as Promise::timeout(), NOT $promise->timeout()
     *
     * @template TTimeoutValue
     * @param  PromiseInterface<TTimeoutValue>  $promise  The promise to add timeout to
     * @param  float  $seconds  Timeout duration in seconds
     * @return PromiseInterface<TTimeoutValue>
     *
     * @static
     */
    public static function timeout(PromiseInterface $promise, float $seconds): PromiseInterface;

    /**
     * Execute multiple tasks with a concurrency limit.
     *
     * IMPORTANT: Tasks MUST be callables that return promises for proper concurrency control.
     * Callables allow the system to control when each task starts executing, enabling true
     * concurrency limiting. Pre-created Promise instances are already running and cannot be
     * subject to concurrency control - they will execute immediately regardless of the limit.
     *
     * Processes tasks in parallel but with concurrency limit to avoid overwhelming the system
     * with too many concurrent operations.
     *
     * !! STATIC METHOD - Must be called as Promise::concurrent(), NOT $promise->concurrent()
     *
     * @template TConcurrentValue
     * @param  iterable<int|string, callable(): PromiseInterface<TConcurrentValue>>  $tasks  Iterable of callable tasks that return promises.
     * @param  int  $concurrency  Maximum number of concurrent executions (default: 10).
     * @return PromiseInterface<array<int|string, TConcurrentValue>> A promise that resolves with an array of all results.
     *
     * @static
     */
    public static function concurrent(iterable $tasks, int $concurrency = 10): PromiseInterface;

    /**
     * Execute multiple tasks in batches with a concurrency limit.
     *
     * IMPORTANT: Tasks MUST be callables that return promises for proper concurrency control.
     * Callables allow the system to control when each task starts executing, enabling true
     * concurrency limiting. Pre-created Promise instances are already running and cannot be
     * subject to concurrency control - they will execute immediately regardless of the limit.
     *
     * This method processes tasks in smaller batches, allowing for
     * controlled concurrency and resource management.
     *
     * !! STATIC METHOD - Must be called as Promise::batch(), NOT $promise->batch()
     *
     * @template TBatchValue
     * @param  iterable<int|string, callable(): PromiseInterface<TBatchValue>>  $tasks  Iterable of tasks that return promises.
     * @param  int  $batchSize  Size of each batch to process concurrently.
     * @param  int|null  $concurrency  Maximum number of concurrent executions per batch.
     * @return PromiseInterface<array<int|string, TBatchValue>> A promise that resolves with all results.
     *
     * @static
     */
    public static function batch(iterable $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface;

    /**
     * Execute multiple tasks concurrently with a specified concurrency limit and wait for all to settle.
     *
     * IMPORTANT: Tasks MUST be callables that return promises for proper concurrency control.
     * Callables allow the system to control when each task starts executing, enabling true
     * concurrency limiting. Pre-created Promise instances are already running and cannot be
     * subject to concurrency control - they will execute immediately regardless of the limit.
     *
     * Similar to concurrent(), but waits for all tasks to complete and returns settlement results.
     * This method never rejects - it always resolves with an array of SettledResult objects.
     *
     * !! STATIC METHOD - Must be called as Promise::concurrentSettled(), NOT $promise->concurrentSettled()
     *
     * @template TConcurrentSettledValue
     * @param  iterable<int|string, callable(): PromiseInterface<TConcurrentSettledValue>>  $tasks  Iterable of tasks that return promises.
     * @param  int  $concurrency  Maximum number of concurrent executions
     * @return PromiseInterface<array<int|string, SettledResultInterface<TConcurrentSettledValue, mixed>>> A promise that resolves with settlement results
     *
     * @static
     */
    public static function concurrentSettled(iterable $tasks, int $concurrency = 10): PromiseInterface;

    /**
     * Execute multiple tasks in batches with a concurrency limit and wait for all to settle.
     *
     * IMPORTANT: Tasks MUST be callables that return promises for proper concurrency control.
     * Callables allow the system to control when each task starts executing, enabling true
     * concurrency limiting. Pre-created Promise instances are already running and cannot be
     * subject to concurrency control - they will execute immediately regardless of the limit.
     *
     * Similar to batch(), but waits for all tasks to complete and returns settlement results.
     * This method never rejects - it always resolves with an array of SettledResult objects.
     *
     * !! STATIC METHOD - Must be called as Promise::batchSettled(), NOT $promise->batchSettled()
     *
     * @template TBatchSettledValue
     * @param  iterable<int|string, callable(): PromiseInterface<TBatchSettledValue>>  $tasks  Iterable of tasks that return promises.
     * @param  int  $batchSize  Size of each batch to process concurrently
     * @param  int|null  $concurrency  Maximum number of concurrent executions per batch
     * @return PromiseInterface<array<int|string, SettledResultInterface<TBatchSettledValue, mixed>>> A promise that resolves with settlement results
     *
     * @static
     */
    public static function batchSettled(iterable $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface;

    /**
     * Iterates over an iterable, transforms each value using a mapper function, and returns a promise that resolves to an array of mapped values.
     *
     * This method automatically manages the execution queue. It pulls items from the iterable
     * and processes them concurrently up to the specified limit.
     *
     * Key Features:
     * - **Input Resolution**: If the input `$items` contains Promises, this method waits for them to resolve before passing the value to the `$mapper`.
     * - **Concurrency Control**: Defaults to a safe limit (10) to prevent resource exhaustion.
     * - **Order Preservation**: The resulting array keys and order match the input iterable.
     *
     * @template TMapItem
     * @template TMapResult
     *
     * @param iterable<int|string, TMapItem> $items Input values. Can be scalar values or Promises.
     * @param callable(TMapItem, int|string): (TMapResult|PromiseInterface<TMapResult>) $mapper Function to transform each item. Receives ($value, $key).
     * @param int|null $concurrency Maximum number of concurrent executions.
     *                              - Pass `null` for **Unlimited** concurrency.
     *
     * @return PromiseInterface<array<int|string, TMapResult>> A promise that resolves with an array of mapped results.
     * @static
     */
    public static function map(iterable $items, callable $mapper, ?int $concurrency = null): PromiseInterface;

    /**
     * Iterates over an iterable, transforms each value using a mapper function, and returns a promise
     * that resolves to an array of settled results — one per item — regardless of individual failures.
     *
     * Behaves identically to {@see map()} in terms of input handling, concurrency control, and order
     * preservation, but never rejects or cancels on individual mapper failures. Each item's outcome
     * is captured as a {@see SettledResult} instead of short-circuiting the entire operation.
     *
     * Key Features:
     * - **Input Resolution**: If the input `$items` contains Promises, this method waits for them to resolve before passing the value to the `$mapper`.
     * - **Never Fails**: The returned promise always fulfills, even if every mapper invocation rejects or is cancelled.
     * - **Concurrency Control**: Defaults to a safe limit (10) to prevent resource exhaustion.
     * - **Order Preservation**: The resulting array keys and order match the input iterable.
     * - **Full Outcome Capture**: Each result is a {@see SettledResult} with a status of fulfilled, rejected, or cancelled.
     *
     * @template TMapItem
     * @template TMapResult
     *
     * @param iterable<int|string, TMapItem> $items Input values. Can be scalar values or Promises.
     * @param callable(TMapItem, int|string): (TMapResult|PromiseInterface<TMapResult>) $mapper Function to transform each item. Receives ($value, $key).
     * @param int|null $concurrency Maximum number of concurrent executions.
     *                              - Pass `null` for **Unlimited** concurrency.
     *
     * @return PromiseInterface<array<int|string, SettledResultInterface<TMapResult, mixed>>> A promise that always resolves with an array of settled results.
     * @static
     */
    public static function mapSettled(iterable $items, callable $mapper, ?int $concurrency = null): PromiseInterface;

    /**
     * Iterates over an iterable, tests each value against a predicate function, and returns
     * a promise that resolves to an array containing only the items that passed.
     *
     * This method automatically manages the execution queue. It pulls items from the iterable
     * and evaluates predicates concurrently up to the specified limit.
     *
     * Key Features:
     * - **Input Resolution**: If the input `$items` contains Promises, this method waits for
     *   them to resolve before passing the value to the `$predicate`.
     * - **Order Preservation**: The resulting array keys and order match the input iterable.
     *   Items are included or excluded based on predicate result, but their relative order
     *   is never changed.
     * - **Key Preservation**: Original keys (string or numeric) are preserved in the output.
     * - **Concurrency Control**: Defaults to a safe limit (10) to prevent resource exhaustion.
     * - **Fail-Fast**: Rejects on the first predicate exception or rejected promise. If you
     *   need resilience, handle errors inside the predicate using ->catch(fn () => false) to
     *   treat failures as a non-passing result.
     *
     * When to use filter() vs map():
     * - Use {@see map()} when you want to transform every item.
     * - Use filter() when you want to select a subset of items based on a condition.
     *
     * Resilient predicate pattern (filterSettled equivalent):
     * ```php
     * Promise::filter($items, function ($item) {
     *     return checkItem($item)
     *         ->catch(fn () => false); // treat predicate errors as non-passing
     * });
     * ```
     *
     * @template TFilterItem
     *
     * @param iterable<int|string, TFilterItem> $items Input values. Can be scalar values or Promises.
     * @param callable(TFilterItem, int|string): (bool|PromiseInterface<bool>) $predicate
     *        Function to test each item. Receives ($value, $key). Must return a bool or
     *        a Promise that resolves to a bool.
     * @param int|null $concurrency Maximum number of concurrent predicate evaluations.
     *                              - Pass `null` for **Unlimited** concurrency.
     *
     * @return PromiseInterface<array<int|string, TFilterItem>>
     *         A promise that resolves with an array of items that passed the predicate,
     *         preserving original keys and order.
     * @static
     */
    public static function filter(iterable $items, callable $predicate, ?int $concurrency = null): PromiseInterface;

    /**
     * Iterates over an iterable sequentially, accumulating a single value by applying
     * a reducer function to each item and the running carry, and returns a promise that
     * resolves to the final accumulated value.
     *
     * Unlike {@see map()} and {@see filter()}, reduce() is inherently sequential —
     * each step waits for the previous one to complete before proceeding, because each
     * iteration receives the result of the prior step as its carry value.
     *
     *  **Sequential**: There is no concurrency parameter. If your reducer does not depend
     * on the previous carry value, consider {@see map()} followed by array_reduce() for
     * better throughput.
     *
     * Key Features:
     * - **Sequential Execution**: Each reducer invocation waits for the previous to settle.
     * - **Input Resolution**: If `$items` contains Promises, each is resolved before being
     *   passed to the reducer.
     * - **Async Reducer**: The reducer may return a plain value or a Promise. If a Promise
     *   is returned, the next step waits for it to resolve before proceeding.
     * - **Fail-Fast**: Rejects immediately if any reducer invocation throws or returns a
     *   rejected promise.
     * - **Empty Input**: Resolves immediately with the initial value if the iterable is empty.
     *
     * @template TReduceItem
     * @template TReduceCarry
     *
     * @param iterable<int|string, TReduceItem> $items
     *        Input values. Can be scalar values or Promises.
     * @param callable(TReduceCarry, TReduceItem, int|string): (TReduceCarry|PromiseInterface<TReduceCarry>) $reducer
     *        Function receiving ($carry, $value, $key). May return a plain value or a Promise.
     * @param TReduceCarry $initial
     *        The initial carry value passed to the first reducer invocation.
     *
     * @return PromiseInterface<TReduceCarry>
     *         A promise that resolves with the final accumulated value.
     * @static
     */
    public static function reduce(iterable $items, callable $reducer, mixed $initial = null): PromiseInterface;

    /**
     * Iterates over an iterable, executes a callback for each value as a side effect, and returns
     * a promise that resolves when all callbacks have completed.
     *
     * Unlike {@see map()}, this method discards all return values immediately after each callback
     * fires. No result array is accumulated, making memory consumption O(concurrency) regardless
     * of how many items are processed. Suitable for processing millions or billions of items from
     * a generator without memory growth.
     *
     * Key Features:
     * - **Input Resolution**: If the input `$items` contains Promises, this method waits for them
     *   to resolve before passing the value to the `$callback`.
     * - **Zero Result Accumulation**: Return values from the callback are discarded immediately.
     *   Memory stays flat for the entire run, bounded only by the concurrency cap.
     * - **Fail-Fast**: Rejects on the first callback exception or rejected promise, cancelling
     *   any in-flight operations. Use {@see forEachSettled()} if you need to process all items
     *   regardless of individual failures.
     * - **Concurrency Control**: Defaults to a safe limit (10) to prevent resource exhaustion.
     *
     * When to use forEach() vs map():
     * - Use {@see map()} when you need the transformed results as an array.
     * - Use forEach() when the callback performs a side effect (writing to a database, sending
     *   an event, logging) and the return value is irrelevant.
     *
     * @template TForEachItem
     *
     * @param iterable<int|string, TForEachItem> $items Input values. Can be scalar values or Promises.
     * @param callable(TForEachItem, int|string): (void|PromiseInterface<void>) $callback Side-effect function to execute per item. Receives ($value, $key).
     * @param int|null $concurrency Maximum number of concurrent executions.
     *                              - Pass `null` for **Unlimited** concurrency.
     *
     * @return PromiseInterface<void> A promise that resolves when all callbacks have completed.
     * @static
     */
    public static function forEach(iterable $items, callable $callback, ?int $concurrency = null): PromiseInterface;

    /**
     * Iterates over an iterable, executes a callback for each value as a side effect, and returns
     * a promise that always resolves when all callbacks have been attempted — regardless of
     * individual failures.
     *
     * Behaves identically to {@see forEach()} in terms of input handling, concurrency control,
     * and memory efficiency, but never rejects or cancels on individual callback failures.
     * Exceptions thrown by the callback and rejected promises returned by it are silently
     * swallowed. The outer promise always fulfills once every item has been attempted.
     *
     * Key Features:
     * - **Input Resolution**: If the input `$items` contains Promises, this method waits for them
     *   to resolve before passing the value to the `$callback`.
     * - **Zero Result Accumulation**: Return values and failure reasons are discarded immediately.
     *   Memory stays flat for the entire run, bounded only by the concurrency cap.
     * - **Never Fails**: The returned promise always fulfills, even if every callback throws or
     *   returns a rejected promise.
     * - **Concurrency Control**: Defaults to a safe limit (10) to prevent resource exhaustion.
     *
     * When to use forEachSettled() vs forEach():
     * - Use {@see forEach()} when a single failure should abort the entire operation.
     * - Use forEachSettled() when failures are expected or acceptable and every item must be
     *   attempted regardless — e.g. sending notifications, writing audit logs, or purging cache
     *   entries where partial failure is tolerable.
     *
     * @template TForEachItem
     *
     * @param iterable<int|string, TForEachItem> $items Input values. Can be scalar values or Promises.
     * @param callable(TForEachItem, int|string): (void|PromiseInterface<void>) $callback Side-effect function to execute per item. Receives ($value, $key).
     * @param int|null $concurrency Maximum number of concurrent executions.
     *                              - Pass `null` for **Unlimited** concurrency.
     *
     * @return PromiseInterface<void> A promise that always resolves once all items have been attempted.
     * @static
     */
    public static function forEachSettled(iterable $items, callable $callback, ?int $concurrency = null): PromiseInterface;
}
