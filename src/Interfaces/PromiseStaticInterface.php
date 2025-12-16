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
 * Promise::race([$promise1, $promise2]);
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
     * If any promise rejects, the returned promise will reject with
     * the first rejection reason.
     *
     * !! STATIC METHOD - Must be called as Promise::all(), NOT $promise->all()
     *
     * @template TAllValue
     * @param  array<int|string, PromiseInterface<TAllValue>>  $promises  Array of PromiseInterface instances.
     * @return PromiseInterface<array<int|string, TAllValue>> A promise that resolves with an array of results.
     *
     * @static
     */
    public static function all(array $promises): PromiseInterface;

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
     * @param  array<int|string, PromiseInterface<TAllSettledValue>>  $promises
     * @return PromiseInterface<array<int|string, SettledResult<TAllSettledValue, mixed>>>
     *
     * @static
     */
    public static function allSettled(array $promises): PromiseInterface;

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
     * @param  array<int|string, PromiseInterface<TRaceValue>>  $promises  Array of PromiseInterface instances.
     * @return PromiseInterface<TRaceValue> A promise that settles with the first settled promise.
     *
     * @static
     */
    public static function race(array $promises): PromiseInterface;

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
     * @param  array<int|string, PromiseInterface<TAnyValue>>  $promises  Array of promises to wait for
     * @return PromiseInterface<TAnyValue> A promise that resolves with the first settled value
     *
     * @static
     */
    public static function any(array $promises): PromiseInterface;

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
     * @param  array<int|string, callable(): PromiseInterface<TConcurrentValue>>  $tasks  Array of callable tasks that return promises. Must be callables for proper concurrency control.
     * @param  int  $concurrency  Maximum number of concurrent executions (default: 10).
     * @return PromiseInterface<array<int|string, TConcurrentValue>> A promise that resolves with an array of all results.
     *
     * @static
     */
    public static function concurrent(array $tasks, int $concurrency = 10): PromiseInterface;

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
     * @param  array<int|string, callable(): PromiseInterface<TBatchValue>>  $tasks  Array of tasks that return promises. Must be callables for proper concurrency control.
     * @param  int  $batchSize  Size of each batch to process concurrently.
     * @param  int|null  $concurrency  Maximum number of concurrent executions per batch.
     * @return PromiseInterface<array<int|string, TBatchValue>> A promise that resolves with all results.
     *
     * @static
     */
    public static function batch(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface;

    /**
     * Execute multiple tasks concurrently with a specified concurrency limit and wait for all to settle.
     *
     * IMPORTANT: Tasks MUST be callables that return promises for proper concurrency control.
     * Callables allow the system to control when each task starts executing, enabling true
     * concurrency limiting. Pre-created Promise instances are already running and cannot be
     * subject to concurrency control - they will execute immediately regardless of the limit.
     *
     * Similar to concurrent(), but waits for all tasks to complete and returns settlement results.
     * This method never rejects - it always resolves with an array of settlement results.
     *
     * !! STATIC METHOD - Must be called as Promise::concurrentSettled(), NOT $promise->concurrentSettled()
     *
     * @template TConcurrentSettledValue
     * @param  array<int|string, callable(): PromiseInterface<TConcurrentSettledValue>>  $tasks  Array of tasks that return promises. Must be callables for proper concurrency control.
     * @param  int  $concurrency  Maximum number of concurrent executions
     * @return PromiseInterface<array<int|string, array{status: 'fulfilled'|'rejected', value?: TConcurrentSettledValue, reason?: mixed}>> A promise that resolves with settlement results
     *
     * @static
     */
    public static function concurrentSettled(array $tasks, int $concurrency = 10): PromiseInterface;

    /**
     * Execute multiple tasks in batches with a concurrency limit and wait for all to settle.
     *
     * IMPORTANT: Tasks MUST be callables that return promises for proper concurrency control.
     * Callables allow the system to control when each task starts executing, enabling true
     * concurrency limiting. Pre-created Promise instances are already running and cannot be
     * subject to concurrency control - they will execute immediately regardless of the limit.
     *
     * Similar to batch(), but waits for all tasks to complete and returns settlement results.
     * This method never rejects - it always resolves with an array of settlement results.
     *
     * !! STATIC METHOD - Must be called as Promise::batchSettled(), NOT $promise->batchSettled()
     *
     * @template TBatchSettledValue
     * @param  array<int|string, callable(): PromiseInterface<TBatchSettledValue>>  $tasks  Array of tasks that return promises. Must be callables for proper concurrency control.
     * @param  int  $batchSize  Size of each batch to process concurrently
     * @param  int|null  $concurrency  Maximum number of concurrent executions per batch
     * @return PromiseInterface<array<int|string, array{status: 'fulfilled'|'rejected', value?: TBatchSettledValue, reason?: mixed}>> A promise that resolves with settlement results
     *
     * @static
     */
    public static function batchSettled(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface;
}
