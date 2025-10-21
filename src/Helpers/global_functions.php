<?php

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

if (! function_exists('all')) {
    /**
     * Wait for all promises to resolve and return their results in order.
     *
     * Creates a promise that resolves when all input promises resolve, with
     * an array of their results in the same order. If any promise rejects,
     * the returned promise immediately rejects with the first rejection reason.
     *
     * @template TAllValue
     * @param  array<int|string, PromiseInterface<TAllValue>|callable(): PromiseInterface<TAllValue>>  $promises  Array of promises to wait for
     * @return PromiseInterface<array<int|string, TAllValue>> A promise that resolves with an array of all results
     */
    function all(array $promises): PromiseInterface
    {
        return Promise::all($promises);
    }
}

if (! function_exists('allSettled')) {
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
    function allSettled(array $promises): PromiseInterface
    {
        return Promise::allSettled($promises);
    }
}

if (! function_exists('race')) {
    /**
     * Return the first promise to settle (resolve or reject).
     *
     * Creates a promise that settles with the same value/reason as the first
     * promise in the array to settle. Useful for timeout scenarios or when
     * you need the fastest response from multiple sources.
     *
     * @template TRaceValue
     * @param  array<int|string, PromiseInterface<TRaceValue>|callable(): PromiseInterface<TRaceValue>>  $promises  Array of promises to race
     * @return PromiseInterface<TRaceValue> A promise that settles with the first result
     */
    function race(array $promises): PromiseInterface
    {
        return Promise::race($promises);
    }
}

if (! function_exists('any')) {
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
    function any(array $promises): PromiseInterface
    {
        return Promise::any($promises);
    }
}

if (! function_exists('timeout')) {
    /**
     * Run an async operation with a timeout limit.
     *
     * Executes the provided promise and ensures it completes within the
     * specified time limit. Automatically throws exception if the timeout timer expires.
     *
     * @template TTimeoutValue
     * @param  PromiseInterface<TTimeoutValue>  $promise  The promise to add timeout to
     * @param  float  $seconds  Number of seconds to wait before timing out
     * @return PromiseInterface<TTimeoutValue> A promise that resolves or rejects based on the original promise or timeout
     */
    function timeout(PromiseInterface $promise, float $seconds): PromiseInterface
    {
        return Promise::timeout($promise, $seconds);
    }
}

if (! function_exists('resolved')) {
    /**
     * Create a promise that is already resolved with the given value.
     *
     * This is useful for creating resolved promises in async workflows or
     * for converting synchronous values into promise-compatible form.
     *
     * @template T
     *
     * @param  T  $value  The value to resolve the promise with
     * @return PromiseInterface<T> A promise resolved with the provided value
     */
    function resolved(mixed $value): PromiseInterface
    {
        return Promise::resolved($value);
    }
}

if (! function_exists('rejected')) {
    /**
     * Create a promise that is already rejected with the given reason.
     *
     * This is useful for creating rejected promises in async workflows or
     * for converting exceptions into promise-compatible form.
     *
     * @param  mixed  $reason  The reason for rejection (typically an exception)
     * @return PromiseInterface<mixed> A promise rejected with the provided reason
     *
     * @example
     * $promise = reject(new Exception('Something went wrong'));
     */
    function rejected(mixed $reason): PromiseInterface
    {
        return Promise::rejected($reason);
    }
}

if (! function_exists('concurrent')) {
    /**
     * Execute multiple tasks concurrently with a specified concurrency limit.
     *
     * IMPORTANT: For proper concurrency control, tasks should be callables that return
     * Promises, not pre-created Promise instances. Pre-created Promises are already
     * running and cannot be subject to concurrency limiting.
     *
     * @template TConcurrentValue
     * @param  array<int|string, callable(): (TConcurrentValue|PromiseInterface<TConcurrentValue>)>  $tasks  Array of callables that return Promises or values
     * @param  int  $concurrency  Maximum number of tasks to run simultaneously
     * @return PromiseInterface<array<int|string, TConcurrentValue>> Promise that resolves with an array of all results
     */
    function concurrent(array $tasks, int $concurrency = 10): PromiseInterface
    {
        return Promise::concurrent($tasks, $concurrency);
    }
}

if (! function_exists('batch')) {
    /**
     * Execute multiple tasks in batches with a concurrency limit.
     *
     * This method processes tasks in smaller batches, allowing for controlled
     * concurrency and resource management. It is particularly useful for
     * processing large datasets or performing operations that require
     * significant resources without overwhelming the system.
     *
     * @template TBatchValue
     * @param  array<int|string, callable(): (TBatchValue|PromiseInterface<TBatchValue>)>  $tasks  Array of callables that return Promises or values
     * @param  int  $batchSize  Size of each batch to process concurrently
     * @param  int|null  $concurrency  Maximum number of concurrent executions per batch
     * @return PromiseInterface<array<int|string, TBatchValue>> A promise that resolves with all results
     */
    function batch(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface
    {
        return Promise::batch($tasks, $batchSize, $concurrency);
    }
}

if (! function_exists('concurrentSettled')) {
    /**
     * Execute multiple tasks concurrently with a specified concurrency limit
     * and wait for it to settle without rejecting (either resolve or reject).
     *
     * - IMPORTANT: For proper concurrency control, tasks should be callables that return
     * Promises, not pre-created Promise instances. Pre-created Promises are already
     * running and cannot be subject to concurrency limiting.
     *
     * @template TConcurrentSettledValue
     * @param  array<int|string, callable(): (TConcurrentSettledValue|PromiseInterface<TConcurrentSettledValue>)>  $tasks  Array of callables that return Promises or values
     * @param  int  $concurrency  Maximum number of tasks to run simultaneously
     * @return PromiseInterface<array<int|string, array{status: 'fulfilled'|'rejected', value?: TConcurrentSettledValue, reason?: mixed}>> Promise that resolves with an array of all settlement results
     */
    function concurrentSettled(array $tasks, int $concurrency = 10): PromiseInterface
    {
        return Promise::concurrentSettled($tasks, $concurrency);
    }
}

if (! function_exists('batchSettled')) {
    /**
     * Execute multiple tasks in batches with a concurrency limit
     * and wait for it to settle without rejecting (either resolve or reject).
     *
     * - IMPORTANT: For proper concurrency control, tasks should be callables that return
     * Promises, not pre-created Promise instances. Pre-created Promises are already
     * running and cannot be subject to concurrency limiting.
     *
     * This method processes tasks in smaller batches, allowing for controlled
     * concurrency and resource management. It is particularly useful for
     * processing large datasets or performing operations that require
     * significant resources without overwhelming the system.
     *
     * @template TBatchSettledValue
     * @param  array<int|string, callable(): (TBatchSettledValue|PromiseInterface<TBatchSettledValue>)>  $tasks  Array of callables that return Promises or values
     * @param  int  $batchSize  Size of each batch to process concurrently
     * @param  int|null  $concurrency  Maximum number of concurrent executions per batch
     * @return PromiseInterface<array<int|string, array{status: 'fulfilled'|'rejected', value?: TBatchSettledValue, reason?: mixed}>> A promise that resolves with all settlement results
     */
    function batchSettled(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface
    {
        return Promise::batchSettled($tasks, $batchSize, $concurrency);
    }
}