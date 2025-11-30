<?php

namespace Hibla\Promise;

use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Wait for all promises to resolve and return their results.
 *
 * If any promise rejects, the returned promise will reject with
 * the first rejection reason.
 *
 * @template TAllValue
 * @param  array<int|string, PromiseInterface<TAllValue>>  $promises  Array of PromiseInterface instances.
 * @return PromiseInterface<array<int|string, TAllValue>> A promise that resolves with an array of results.
 */
function all(array $promises): PromiseInterface
{
    return Promise::all($promises);
}

/**
 * Wait for all promises to settle (either resolve or reject).
 *
 * Unlike all(), this method waits for every promise to complete and returns
 * all results, including both successful values and rejection reasons.
 * This method never rejects - it always resolves with an array of settlement results.
 *
 * @template TAllSettledValue
 * @param  array<int|string, PromiseInterface<TAllSettledValue>>  $promises
 * @return PromiseInterface<array<int|string, array{status: 'fulfilled'|'rejected', value?: TAllSettledValue, reason?: mixed}>>
 */
function allSettled(array $promises): PromiseInterface
{
    return Promise::allSettled($promises);
}

/**
 * Wait for the first promise to resolve or reject.
 *
 * Returns a promise that settles with the same value/reason as
 * the first promise to settle. Once a promise settles, all other
 * promises that are instances of CancellablePromise will be automatically
 * cancelled to free up resources.
 *
 * @template TRaceValue
 * @param  array<int|string, PromiseInterface<TRaceValue>>  $promises  Array of PromiseInterface instances.
 * @return CancellablePromiseInterface<TRaceValue> A promise that settles with the first settled promise.
 */
function race(array $promises): CancellablePromiseInterface
{
    return Promise::race($promises);
}

/**
 * Wait for any promise in the collection to resolve.
 *
 * Returns a promise that resolves with the value of the first
 * promise that resolves, or rejects if all promises reject.
 * Once a promise resolves, all other promises that are instances
 * of CancellablePromise will be automatically cancelled to free up resources.
 *
 * @template TAnyValue
 * @param  array<int|string, PromiseInterface<TAnyValue>>  $promises  Array of promises to wait for
 * @return CancellablePromiseInterface<TAnyValue> A promise that resolves with the first settled value
 */
function any(array $promises): CancellablePromiseInterface
{
    return Promise::any($promises);
}

/**
 * Create a promise that resolves or rejects with a timeout.
 *
 * @template TTimeoutValue
 * @param  PromiseInterface<TTimeoutValue>  $promise  The promise to add timeout to
 * @param  float  $seconds  Timeout duration in seconds
 * @return CancellablePromiseInterface<TTimeoutValue>
 */
function timeout(PromiseInterface $promise, float $seconds): CancellablePromiseInterface
{
    return Promise::timeout($promise, $seconds);
}

/**
 * Create a resolved promise with the given value.
 *
 * @template TResolveValue
 *
 * @param  TResolveValue  $value  The value to resolve the promise with
 * @return PromiseInterface<TResolveValue> A promise resolved with the provided value
 */
function resolved(mixed $value): PromiseInterface
{
    return Promise::resolved($value);
}

/**
 * Create a rejected promise with the given reason.
 *
 * @param  mixed  $reason  The reason for rejection (typically an exception)
 * @return PromiseInterface<mixed> A promise rejected with the provided reason
 */
function rejected(mixed $reason): PromiseInterface
{
    return Promise::rejected($reason);
}

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
 * @template TConcurrentValue
 * @param  array<int|string, callable(): PromiseInterface<TConcurrentValue>>  $tasks  Array of callable tasks that return promises. Must be callables for proper concurrency control.
 * @param  int  $concurrency  Maximum number of concurrent executions (default: 10).
 * @return PromiseInterface<array<int|string, TConcurrentValue>> A promise that resolves with an array of all results.
 */
function concurrent(array $tasks, int $concurrency = 10): PromiseInterface
{
    return Promise::concurrent($tasks, $concurrency);
}

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
 * @template TBatchValue
 * @param  array<int|string, callable(): PromiseInterface<TBatchValue>>  $tasks  Array of tasks that return promises. Must be callables for proper concurrency control.
 * @param  int  $batchSize  Size of each batch to process concurrently.
 * @param  int|null  $concurrency  Maximum number of concurrent executions per batch.
 * @return PromiseInterface<array<int|string, TBatchValue>> A promise that resolves with all results.
 */
function batch(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface
{
    return Promise::batch($tasks, $batchSize, $concurrency);
}

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
 * @template TConcurrentSettledValue
 * @param  array<int|string, callable(): PromiseInterface<TConcurrentSettledValue>>  $tasks  Array of tasks that return promises. Must be callables for proper concurrency control.
 * @param  int  $concurrency  Maximum number of concurrent executions
 * @return PromiseInterface<array<int|string, array{status: 'fulfilled'|'rejected', value?: TConcurrentSettledValue, reason?: mixed}>> A promise that resolves with settlement results
 */
function concurrentSettled(array $tasks, int $concurrency = 10): PromiseInterface
{
    return Promise::concurrentSettled($tasks, $concurrency);
}

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
 * @template TBatchSettledValue
 * @param  array<int|string, callable(): PromiseInterface<TBatchSettledValue>>  $tasks  Array of tasks that return promises. Must be callables for proper concurrency control.
 * @param  int  $batchSize  Size of each batch to process concurrently
 * @param  int|null  $concurrency  Maximum number of concurrent executions per batch
 * @return PromiseInterface<array<int|string, array{status: 'fulfilled'|'rejected', value?: TBatchSettledValue, reason?: mixed}>> A promise that resolves with settlement results
 */
function batchSettled(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface
{
    return Promise::batchSettled($tasks, $batchSize, $concurrency);
}