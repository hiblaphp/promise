<?php

namespace Hibla\Promise\Interfaces;

/**
 * Interface for promise collection operations.
 *
 * This interface defines static methods for working with collections of promises,
 * including operations like waiting for all promises, racing promises, and
 * managing concurrent execution with limits.
 */
interface PromiseCollectionInterface
{
    /**
     * Create a resolved promise with the given value.
     *
     * @template TResolveValue
     *
     * @param  TResolveValue  $value  The value to resolve the promise with
     * @return PromiseInterface<TResolveValue> A promise resolved with the provided value
     */
    public static function resolved(mixed $value): PromiseInterface;

    /**
     * Create a rejected promise with the given reason.
     *
     * @param  mixed  $reason  The reason for rejection (typically an exception)
     * @return PromiseInterface<mixed> A promise rejected with the provided reason
     */
    public static function rejected(mixed $reason): PromiseInterface;

    /**
     * Wait for all promises to resolve and return their results.
     *
     * If any promise rejects, the returned promise will reject with
     * the first rejection reason.
     *
     * @template TAllValue
     * @param  array<int|string, PromiseInterface<TAllValue>|callable(): PromiseInterface<TAllValue>>  $promises  Array of promises to wait for
     * @return PromiseInterface<array<int|string, TAllValue>> A promise that resolves with an array of results
     */
    public static function all(array $promises): PromiseInterface;

    /**
     * Wait for all promises to settle (either resolve or reject).
     *
     * Unlike all(), this method waits for every promise to complete and returns
     * all results, including both successful values and rejection reasons.
     * This method never rejects - it always resolves with an array of settlement results.
     *
     * @template TAllSettledValue
     * @param  array<int|string, PromiseInterface<TAllSettledValue>|callable(): PromiseInterface<TAllSettledValue>>  $promises
     * @return PromiseInterface<array<int|string, array{status: 'fulfilled'|'rejected', value?: TAllSettledValue, reason?: mixed}>>
     */
    public static function allSettled(array $promises): PromiseInterface;

    /**
     * Wait for the first promise to resolve or reject.
     *
     * Returns a promise that settles with the same value/reason as
     * the first promise to settle.
     *
     * @template TRaceValue
     * @param  array<int|string, PromiseInterface<TRaceValue>|callable(): PromiseInterface<TRaceValue>>  $promises  Array of promises to race
     * @return PromiseInterface<TRaceValue> A promise that settles with the first result
     */
    public static function race(array $promises): PromiseInterface;

    /**
     * Wait for any promise in the collection to resolve.
     *
     * Returns a promise that resolves with the value of the first
     * promise that resolves, or rejects if all promises reject.
     *
     * @template TAnyValue
     * @param  array<int|string, PromiseInterface<TAnyValue>|callable(): PromiseInterface<TAnyValue>>  $promises  Array of promises to wait for
     * @return PromiseInterface<TAnyValue> A promise that resolves with the first settled value
     */
    public static function any(array $promises): PromiseInterface;

    /**
     * Create a promise that resolves or rejects with a timeout.
     *
     * @template TTimeoutValue
     * @param  PromiseInterface<TTimeoutValue>  $promise  Promise to timeout
     * @param  float  $seconds  Timeout in seconds
     * @return PromiseInterface<TTimeoutValue>
     */
    public static function timeout(PromiseInterface $promise, float $seconds): PromiseInterface;

    /**
     * Execute multiple tasks with a concurrency limit.
     *
     * - IMPORTANT: For proper concurrency control, tasks should be callables that return
     * Promises, not pre-created Promise instances. Pre-created Promises are already
     * running and cannot be subject to concurrency limiting.
     *
     * Processes tasks in parallel but with concurrency limit to avoid overwhelming the system
     * with too many concurrent operations.
     *
     * @template TConcurrentValue
     * @param  array<int|string, callable(): (TConcurrentValue|PromiseInterface<TConcurrentValue>)>  $tasks  Array of tasks (callables) to execute
     * @param  int  $concurrency  Maximum number of concurrent executions
     * @return PromiseInterface<array<int|string, TConcurrentValue>> A promise that resolves with all results
     */
    public static function concurrent(array $tasks, int $concurrency = 10): PromiseInterface;

    /**
     * Execute multiple tasks in batches with a concurrency limit.
     *
     * - IMPORTANT: For proper concurrency control, tasks should be callables that return
     * Promises, not pre-created Promise instances. Pre-created Promises are already
     * running and cannot be subject to concurrency limiting.
     *
     * This method processes tasks in smaller batches, allowing for
     * controlled concurrency and resource management.
     *
     * @template TBatchValue
     * @param  array<int|string, callable(): (TBatchValue|PromiseInterface<TBatchValue>)>  $tasks  Array of tasks (callables) to execute
     * @param  int  $batchSize  Size of each batch to process concurrently
     * @param  int|null  $concurrency  Maximum number of concurrent executions per batch
     * @return PromiseInterface<array<int|string, TBatchValue>> A promise that resolves with all results
     */
    public static function batch(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface;

    /**
     * Execute multiple tasks concurrently with a specified concurrency limit and wait for all to settle.
     *
     * - IMPORTANT: For proper concurrency control, tasks should be callables that return
     * Promises, not pre-created Promise instances. Pre-created Promises are already
     * running and cannot be subject to concurrency limiting.
     *
     * Similar to concurrent(), but waits for all tasks to complete and returns settlement results.
     * This method never rejects - it always resolves with an array of settlement results.
     *
     * @template TConcurrentSettledValue
     * @param  array<int|string, callable(): (TConcurrentSettledValue|PromiseInterface<TConcurrentSettledValue>)>  $tasks  Array of tasks (callables) to execute
     * @param  int  $concurrency  Maximum number of concurrent executions
     * @return PromiseInterface<array<int|string, array{status: 'fulfilled'|'rejected', value?: TConcurrentSettledValue, reason?: mixed}>> A promise that resolves with settlement results
     */
    public static function concurrentSettled(array $tasks, int $concurrency = 10): PromiseInterface;

    /**
     * Execute multiple tasks in batches with a concurrency limit and wait for all to settle.
     *
     * - IMPORTANT: For proper concurrency control, tasks should be callables that return
     * Promises, not pre-created Promise instances. Pre-created Promises are already
     * running and cannot be subject to concurrency limiting.
     *
     * Similar to batch(), but waits for all tasks to complete and returns settlement results.
     * This method never rejects - it always resolves with an array of settlement results.
     *
     * @template TBatchSettledValue
     * @param  array<int|string, callable(): (TBatchSettledValue|PromiseInterface<TBatchSettledValue>)>  $tasks  Array of tasks (callables) to execute
     * @param  int  $batchSize  Size of each batch to process concurrently
     * @param  int|null  $concurrency  Maximum number of concurrent executions per batch
     * @return PromiseInterface<array<int|string, array{status: 'fulfilled'|'rejected', value?: TBatchSettledValue, reason?: mixed}>> A promise that resolves with settlement results
     */
    public static function batchSettled(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface;

    /**
     * Reset the static AsyncOperations instance for testing purposes.
     *
     * This method is primarily intended for use in unit tests to ensure
     * a clean state between test runs. It clears the shared AsyncOperations
     * instance, forcing a new one to be created on the next static method call.
     */
    public static function reset(): void;

    /**
     * Checks if the promise is settled (either resolved or rejected).
     *
     * @return bool True if settled, false if still pending.
     */
    public function isSettled(): bool;
}
