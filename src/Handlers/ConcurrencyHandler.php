<?php

declare(strict_types=1);

namespace Hibla\Promise\Handlers;

use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\PromiseCancelledException;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Promise\SettledResult;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class ConcurrencyHandler
{
    /**
     * @template TConcurrentValue
     * @param  array<int|string, callable(): PromiseInterface<TConcurrentValue>>  $tasks
     * @param  int  $concurrency
     * @return PromiseInterface<array<int|string, TConcurrentValue>>
     */
    public function concurrent(array $tasks, int $concurrency = 10): PromiseInterface
    {
        /** @var array<int|string, PromiseInterface<TConcurrentValue>> */
        $promiseInstances = [];

        /** @var Promise<array<int|string, TConcurrentValue>> */
        $concurrentPromise = new Promise(function (callable $resolve, callable $reject) use ($tasks, $concurrency, &$promiseInstances): void {
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
            $isRejected = false;

            $processNext = function () use (
                &$processNext,
                &$taskList,
                &$originalKeys,
                &$running,
                &$completed,
                &$results,
                &$total,
                &$taskIndex,
                &$isRejected,
                &$promiseInstances,
                $concurrency,
                $resolve,
                $reject,
            ): void {
                while ($running < $concurrency && $taskIndex < $total && ! $isRejected) {
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

                        if ($promise->isCancelled()) {
                            $this->cancelAllPromises($promiseInstances);
                            $reject(new PromiseCancelledException(
                                \sprintf('Promise at index "%s" was cancelled', $originalKey)
                            ));
                            $isRejected = true;

                            return;
                        }

                        $promiseInstances[$originalKey] = $promise;
                    } catch (Throwable $e) {
                        $this->cancelAllPromises($promiseInstances);
                        $reject($e);
                        $isRejected = true;

                        return;
                    }

                    $promise
                        ->then(function ($result) use (
                            $originalKey,
                            &$results,
                            &$running,
                            &$completed,
                            &$originalKeys,
                            &$isRejected,
                            $total,
                            $resolve,
                            $processNext,
                        ): void {
                            // @phpstan-ignore-next-line Promise can be rejected at runtime
                            if ($isRejected) {
                                return;
                            }

                            $results[$originalKey] = $result;
                            $running--;
                            $completed++;

                            if ($completed === $total) {
                                $resolve($this->orderResultsByKeys($results, $originalKeys));
                            } else {
                                Loop::microTask($processNext);
                            }
                        })
                        ->catch(function ($error) use (&$isRejected, &$promiseInstances, $reject): void {
                            if ($isRejected) {
                                return;
                            }
                            $isRejected = true;
                            $this->cancelAllPromises($promiseInstances);
                            $reject($error);
                        })
                    ;
                }
            };

            Loop::microTask($processNext);
        });

        $concurrentPromise->onCancel(function () use (&$promiseInstances): void {
            $this->cancelAllPromises($promiseInstances);
        });

        return $concurrentPromise;
    }

    /**
     * @template TConcurrentSettledValue
     * @param  array<int|string, callable(): PromiseInterface<TConcurrentSettledValue>>  $tasks
     * @param  int  $concurrency
     * @return PromiseInterface<array<int|string, SettledResult<TConcurrentSettledValue, mixed>>>
     */
    public function concurrentSettled(array $tasks, int $concurrency = 10): PromiseInterface
    {
        /** @var array<int|string, PromiseInterface<TConcurrentSettledValue>> */
        $promiseInstances = [];

        /** @var Promise<array<int|string, SettledResult<TConcurrentSettledValue, mixed>>> */
        $concurrentPromise = new Promise(function (callable $resolve, callable $reject) use ($tasks, $concurrency, &$promiseInstances): void {
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
                &$promiseInstances,
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
                            $running--;
                            $results[$originalKey] = SettledResult::rejected(
                                new RuntimeException(
                                    \sprintf(
                                        'Task at index "%s" must return a PromiseInterface, %s given',
                                        $originalKey,
                                        get_debug_type($promise)
                                    )
                                )
                            );
                            $completed++;

                            if ($completed === $total) {
                                $resolve($this->orderResultsByKeys($results, $originalKeys));

                                return;
                            }

                            continue;
                        }

                        if ($promise->isCancelled()) {
                            $running--;
                            $results[$originalKey] = SettledResult::cancelled();
                            $completed++;

                            if ($completed === $total) {
                                $resolve($this->orderResultsByKeys($results, $originalKeys));

                                return;
                            }

                            continue;
                        }

                        $promiseInstances[$originalKey] = $promise;
                    } catch (Throwable $e) {
                        $running--;
                        $results[$originalKey] = SettledResult::rejected($e);
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
                            $results[$originalKey] = SettledResult::fulfilled($result);
                            $running--;
                            $completed++;

                            if ($completed === $total) {
                                $resolve($this->orderResultsByKeys($results, $originalKeys));
                            } else {
                                Loop::microTask($processNext);
                            }
                        })
                        ->catch(function ($error) use (
                            $originalKey,
                            &$results,
                            &$running,
                            &$completed,
                            &$originalKeys,
                            &$promiseInstances,
                            $total,
                            $resolve,
                            $processNext,
                        ): void {
                            if (isset($promiseInstances[$originalKey]) && $promiseInstances[$originalKey]->isCancelled()) {
                                $results[$originalKey] = SettledResult::cancelled();
                            } else {
                                $results[$originalKey] = SettledResult::rejected($error);
                            }
                            $running--;
                            $completed++;

                            if ($completed === $total) {
                                $resolve($this->orderResultsByKeys($results, $originalKeys));
                            } else {
                                Loop::microTask($processNext);
                            }
                        })
                    ;
                }
            };

            Loop::microTask($processNext);
        });

        $concurrentPromise->onCancel(function () use (&$promiseInstances): void {
            $this->cancelAllPromises($promiseInstances);
        });

        return $concurrentPromise;
    }

    /**
     * @template TBatchValue
     * @param  array<int|string, callable(): PromiseInterface<TBatchValue>>  $tasks
     * @param  int  $batchSize
     * @param  int|null  $concurrency
     * @return PromiseInterface<array<int|string, TBatchValue>>
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
                $reject,
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
                        Loop::microTask($processNextBatch);
                    })
                    ->catch($reject)
                ;
            };

            Loop::microTask($processNextBatch);
        });
    }

    /**
     * @template TBatchSettledValue
     * @param  array<int|string, callable(): PromiseInterface<TBatchSettledValue>>  $tasks
     * @param  int  $batchSize
     * @param  int|null  $concurrency
     * @return PromiseInterface<array<int|string, SettledResult<TBatchSettledValue, mixed>>>
     */
    public function batchSettled(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface
    {
        /** @var Promise<array<int|string, SettledResult<TBatchSettledValue, mixed>>> */
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
                        $processNextBatch,
                    ): void {
                        foreach ($batchResults as $key => $result) {
                            $allResults[$key] = $result;
                        }
                        $batchIndex++;
                        Loop::microTask($processNextBatch);
                    })
                ;
            };

            Loop::microTask($processNextBatch);
        });
    }

    /**
     * @param  array<int|string>  $keys
     * @return array<int|string, null>
     */
    private function initializeResults(array $keys): array
    {
        return array_fill_keys($keys, null);
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

    /**
     * @param  array<int|string, PromiseInterface<mixed>>  $promiseInstances
     * @return void
     */
    private function cancelAllPromises(array $promiseInstances): void
    {
        foreach ($promiseInstances as $promise) {
            $promise->cancelChain();
        }
    }
}
