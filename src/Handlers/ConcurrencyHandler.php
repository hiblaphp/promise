<?php

declare(strict_types=1);

namespace Hibla\Promise\Handlers;

use Hibla\EventLoop\Loop;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class ConcurrencyHandler
{
    /**
     * @template TConcurrentValue
     * @param  array<int|string, callable(): PromiseInterface<TConcurrentValue>>  $tasks  Array of callable tasks that return promises.
     * @param  int  $concurrency  Maximum number of concurrent executions (default: 10).
     * @return PromiseInterface<array<int|string, TConcurrentValue>> A promise that resolves with an array of all results.
     */
    public function concurrent(array $tasks, int $concurrency = 10): PromiseInterface
    {
        /** @var Promise<array<int|string, TConcurrentValue>> */
        return new Promise(function (callable $resolve, callable $reject) use ($tasks, $concurrency): void {
            if ($concurrency <= 0) {
                $reject(new InvalidArgumentException('Concurrency limit must be greater than 0'));

                return;
            }

            if ($tasks === []) {
                $resolve([]);

                return;
            }

            $originalKeys = array_keys($tasks);
            $taskList = array_values($tasks);
            $results = $this->initializeResults($originalKeys);

            $running = 0;
            $completed = 0;
            $total = \count($taskList);
            $taskIndex = 0;

            $processNext = function () use (
                &$processNext,
                &$taskList,
                &$originalKeys,
                &$running,
                &$completed,
                &$results,
                &$total,
                &$taskIndex,
                $concurrency,
                $resolve,
                $reject,
            ): void {
                while ($running < $concurrency && $taskIndex < $total) {
                    $currentIndex = $taskIndex++;
                    $task = $taskList[$currentIndex];
                    $originalKey = $originalKeys[$currentIndex];
                    $running++;

                    try {
                        $promise = $task();

                        /** @phpstan-ignore-next-line instanceof.alwaysTrue */
                        if (! ($promise instanceof PromiseInterface)) {
                            throw new RuntimeException(
                                \sprintf(
                                    'Task at index "%s" must return a PromiseInterface, %s given',
                                    $originalKey,
                                    get_debug_type($promise)
                                )
                            );
                        }
                    } catch (Throwable $e) {
                        $reject($e);

                        return;
                    }

                    $promise
                        ->then(function ($result) use (
                            $originalKey,
                            &$results,
                            &$running,
                            &$completed,
                            &$originalKeys,
                            $total,
                            $resolve,
                            $processNext,
                        ): void {
                            $results[$originalKey] = $result;
                            $running--;
                            $completed++;

                            if ($completed === $total) {
                                $resolve($this->orderResultsByKeys($results, $originalKeys));
                            } else {
                                Loop::nextTick($processNext);
                            }
                        })
                        ->catch(function ($error) use ($reject): void {
                            $reject($error);
                        })
                    ;
                }
            };

            Loop::nextTick($processNext);
        });
    }

    /**
     * @template TConcurrentSettledValue
     * @param  array<int|string, callable(): PromiseInterface<TConcurrentSettledValue>>  $tasks  Array of tasks that return promises
     * @param  int  $concurrency  Maximum number of concurrent executions
     * @return PromiseInterface<array<int|string, array{status: 'fulfilled'|'rejected', value?: TConcurrentSettledValue, reason?: mixed}>> A promise that resolves with settlement results
     */
    public function concurrentSettled(array $tasks, int $concurrency = 10): PromiseInterface
    {
        /** @var Promise<array<int|string, array{status: 'fulfilled'|'rejected', value?: TConcurrentSettledValue, reason?: mixed}>> */
        return new Promise(function (callable $resolve, callable $reject) use ($tasks, $concurrency): void {
            if ($concurrency <= 0) {
                $reject(new InvalidArgumentException('Concurrency limit must be greater than 0'));

                return;
            }

            if ($tasks === []) {
                $resolve([]);

                return;
            }

            $originalKeys = array_keys($tasks);
            $taskList = array_values($tasks);
            $results = $this->initializeResults($originalKeys);

            $running = 0;
            $completed = 0;
            $total = \count($taskList);
            $taskIndex = 0;

            $processNext = function () use (
                &$processNext,
                &$taskList,
                &$originalKeys,
                &$running,
                &$completed,
                &$results,
                &$total,
                &$taskIndex,
                $concurrency,
                $resolve,
            ): void {
                while ($running < $concurrency && $taskIndex < $total) {
                    $currentIndex = $taskIndex++;
                    $task = $taskList[$currentIndex];
                    $originalKey = $originalKeys[$currentIndex];
                    $running++;

                    try {
                        $promise = $task();

                        /** @phpstan-ignore-next-line instanceof.alwaysTrue */
                        if (! ($promise instanceof PromiseInterface)) {
                            throw new RuntimeException(
                                \sprintf(
                                    'Task at index "%s" must return a PromiseInterface, %s given',
                                    $originalKey,
                                    get_debug_type($promise)
                                )
                            );
                        }
                    } catch (Throwable $e) {
                        $running--;
                        $results[$originalKey] = [
                            'status' => 'rejected',
                            'reason' => $e,
                        ];
                        $completed++;

                        if ($completed === $total) {
                            $resolve($this->orderResultsByKeys($results, $originalKeys));

                            return;
                        }

                        continue;
                    }

                    $promise
                        ->then(function ($result) use (
                            $originalKey,
                            &$results,
                            &$running,
                            &$completed,
                            &$originalKeys,
                            $total,
                            $resolve,
                            $processNext,
                        ): void {
                            $results[$originalKey] = [
                                'status' => 'fulfilled',
                                'value' => $result,
                            ];
                            $running--;
                            $completed++;

                            if ($completed === $total) {
                                $resolve($this->orderResultsByKeys($results, $originalKeys));
                            } else {
                                Loop::nextTick($processNext);
                            }
                        })
                        ->catch(function ($error) use (
                            $originalKey,
                            &$results,
                            &$running,
                            &$completed,
                            &$originalKeys,
                            $total,
                            $resolve,
                            $processNext,
                        ): void {
                            $results[$originalKey] = [
                                'status' => 'rejected',
                                'reason' => $error,
                            ];
                            $running--;
                            $completed++;

                            if ($completed === $total) {
                                $resolve($this->orderResultsByKeys($results, $originalKeys));
                            } else {
                                Loop::nextTick($processNext);
                            }
                        })
                    ;
                }
            };

            Loop::nextTick($processNext);
        });
    }

    /**
     * @template TBatchValue
     * @param  array<int|string, callable(): PromiseInterface<TBatchValue>>  $tasks  Array of tasks that return promises.
     * @param  int  $batchSize  Size of each batch to process concurrently.
     * @param  int|null  $concurrency  Maximum number of concurrent executions per batch.
     * @return PromiseInterface<array<int|string, TBatchValue>> A promise that resolves with all results.
     */
    public function batch(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface
    {
        /** @var Promise<array<int|string, TBatchValue>> */
        return new Promise(function (callable $resolve, callable $reject) use ($tasks, $batchSize, $concurrency): void {
            if ($batchSize <= 0) {
                $reject(new InvalidArgumentException('Batch size must be greater than 0'));

                return;
            }

            if ($tasks === []) {
                $resolve([]);

                return;
            }

            $concurrency ??= $batchSize;

            if ($concurrency <= 0) {
                $reject(new InvalidArgumentException('Concurrency limit must be greater than 0'));

                return;
            }

            $originalKeys = array_keys($tasks);
            $taskValues = array_values($tasks);

            /** @var int<1, max> $validBatchSize */
            $validBatchSize = $batchSize;

            $batches = array_chunk($taskValues, $validBatchSize, false);
            $keyBatches = array_chunk($originalKeys, $validBatchSize, false);

            $allResults = $this->initializeResults($originalKeys);
            $batchIndex = 0;
            $totalBatches = \count($batches);

            $processNextBatch = function () use (
                &$processNextBatch,
                &$batches,
                &$keyBatches,
                &$allResults,
                &$batchIndex,
                &$originalKeys,
                $totalBatches,
                $concurrency,
                $resolve,
                $reject
            ): void {
                if ($batchIndex >= $totalBatches) {
                    $resolve($this->orderResultsByKeys($allResults, $originalKeys));

                    return;
                }

                $currentBatch = $batches[$batchIndex];
                $currentKeys = $keyBatches[$batchIndex];
                $batchTasks = array_combine($currentKeys, $currentBatch);

                $this->concurrent($batchTasks, $concurrency)
                    ->then(function ($batchResults) use (
                        &$allResults,
                        &$batchIndex,
                        $processNextBatch,
                    ): void {
                        foreach ($batchResults as $key => $result) {
                            $allResults[$key] = $result;
                        }
                        $batchIndex++;
                        Loop::nextTick($processNextBatch);
                    })
                    ->catch($reject)
                ;
            };

            Loop::nextTick($processNextBatch);
        });
    }

    /**
     * @template TBatchSettledValue
     * @param  array<int|string, callable(): PromiseInterface<TBatchSettledValue>>  $tasks  Array of tasks that return promises
     * @param  int  $batchSize  Size of each batch to process concurrently
     * @param  int|null  $concurrency  Maximum number of concurrent executions per batch
     * @return PromiseInterface<array<int|string, array{status: 'fulfilled'|'rejected', value?: TBatchSettledValue, reason?: mixed}>> A promise that resolves with settlement results
     */
    public function batchSettled(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface
    {
        /** @var Promise<array<int|string, array{status: 'fulfilled'|'rejected', value?: TBatchSettledValue, reason?: mixed}>> */
        return new Promise(function (callable $resolve, callable $reject) use ($tasks, $batchSize, $concurrency): void {
            if ($batchSize <= 0) {
                $reject(new InvalidArgumentException('Batch size must be greater than 0'));

                return;
            }

            if ($tasks === []) {
                $resolve([]);

                return;
            }

            $concurrency ??= $batchSize;

            if ($concurrency <= 0) {
                $reject(new InvalidArgumentException('Concurrency limit must be greater than 0'));

                return;
            }

            $originalKeys = array_keys($tasks);
            $taskValues = array_values($tasks);

            /** @var int<1, max> $validBatchSize */
            $validBatchSize = $batchSize;

            $batches = array_chunk($taskValues, $validBatchSize, false);
            $keyBatches = array_chunk($originalKeys, $validBatchSize, false);

            $allResults = $this->initializeResults($originalKeys);
            $batchIndex = 0;
            $totalBatches = \count($batches);

            $processNextBatch = function () use (
                &$processNextBatch,
                &$batches,
                &$keyBatches,
                &$allResults,
                &$batchIndex,
                &$originalKeys,
                $totalBatches,
                $concurrency,
                $resolve
            ): void {
                if ($batchIndex >= $totalBatches) {
                    $resolve($this->orderResultsByKeys($allResults, $originalKeys));

                    return;
                }

                $currentBatch = $batches[$batchIndex];
                $currentKeys = $keyBatches[$batchIndex];
                $batchTasks = array_combine($currentKeys, $currentBatch);

                $this->concurrentSettled($batchTasks, $concurrency)
                    ->then(function ($batchResults) use (
                        &$allResults,
                        &$batchIndex,
                        $processNextBatch
                    ): void {
                        foreach ($batchResults as $key => $result) {
                            $allResults[$key] = $result;
                        }
                        $batchIndex++;
                        Loop::nextTick($processNextBatch);
                    })
                ;
            };

            Loop::nextTick($processNextBatch);
        });
    }

    /**
     * @param  array<int|string>  $keys
     * @return array<int|string, null>
     */
    private function initializeResults(array $keys): array
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = null;
        }

        return $results;
    }

    /**
     * @param  array<int|string, mixed>  $results
     * @param  array<int|string>  $keys
     * @return array<int|string, mixed>
     */
    private function orderResultsByKeys(array $results, array $keys): array
    {
        $ordered = [];
        foreach ($keys as $key) {
            $ordered[$key] = $results[$key];
        }

        return $ordered;
    }
}
