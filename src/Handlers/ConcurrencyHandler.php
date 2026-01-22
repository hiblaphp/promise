<?php

declare(strict_types=1);

namespace Hibla\Promise\Handlers;

use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\CancelledException;
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
     * @param  iterable<int|string, callable(): PromiseInterface<TConcurrentValue>>  $tasks
     * @param  int  $concurrency
     * @return PromiseInterface<array<int|string, TConcurrentValue>>
     */
    public function concurrent(iterable $tasks, int $concurrency = 10): PromiseInterface
    {
        /** @var array<int|string, PromiseInterface<TConcurrentValue>> $promiseInstances */
        $promiseInstances = [];

        /** @var Promise<array<int|string, TConcurrentValue>> $concurrentPromise */
        $concurrentPromise = new Promise(function (callable $resolve, callable $reject) use ($tasks, $concurrency, &$promiseInstances): void {
            if ($concurrency <= 0) {
                $reject(new InvalidArgumentException('Concurrency limit must be greater than 0'));

                return;
            }

            $iterator = $this->getIterator($tasks);
            $iterator->rewind();

            if (! $iterator->valid()) {
                $resolve([]);

                return;
            }

            $results = [];
            $keyOrder = [];
            $running = 0;
            $state = (object) ['isRejected' => false, 'exhausted' => false, 'scheduled' => false];

            $processNext = function () use (
                &$processNext,
                $iterator,
                &$running,
                &$results,
                &$keyOrder,
                $state,
                &$promiseInstances,
                $concurrency,
                $resolve,
                $reject,
            ): void {
                $state->scheduled = false;
                if ($state->isRejected) {
                    return;
                }

                while ($running < $concurrency && $iterator->valid()) {
                    $key = $iterator->key();
                    $task = $iterator->current();

                    $keyOrder[] = $key;
                    $iterator->next();
                    $running++;

                    try {
                        $promise = $task();

                        if (! $promise instanceof PromiseInterface) {
                            throw new RuntimeException(\sprintf(
                                'Task at key "%s" must return a PromiseInterface, %s given',
                                $key,
                                \get_debug_type($promise)
                            ));
                        }

                        if ($promise->isCancelled()) {
                            $state->isRejected = true;
                            $this->cancelAll($promiseInstances);
                            $reject(new CancelledException(\sprintf('Promise at key "%s" was cancelled', $key)));

                            return;
                        }

                        $promiseInstances[$key] = $promise;

                        $promise->onCancel(function () use ($key, $state, &$promiseInstances, $reject): void {
                            if ($state->isRejected) {
                                return;
                            }
                            $state->isRejected = true;
                            $this->cancelAll($promiseInstances);
                            $reject(new CancelledException(\sprintf('Promise at key "%s" was cancelled', $key)));
                        });
                    } catch (Throwable $e) {
                        $state->isRejected = true;
                        $this->cancelAll($promiseInstances);
                        $reject($e);

                        return;
                    }

                    $promise->then(
                        function ($result) use ($key, &$results, &$running, &$keyOrder, $state, $resolve, $processNext): void {
                            if ($state->isRejected) {
                                return;
                            }

                            $results[$key] = $result;
                            $running--;

                            if ($state->exhausted && $running === 0) {
                                $resolve($this->reorder($keyOrder, $results));
                            } elseif (! $state->scheduled) {
                                $state->scheduled = true;
                                Loop::microTask($processNext);
                            }
                        },
                        function ($error) use ($state, &$promiseInstances, $reject): void {
                            if ($state->isRejected) {
                                return;
                            }
                            $state->isRejected = true;
                            $this->cancelAll($promiseInstances);
                            $reject($error);
                        }
                    );
                }

                if (! $iterator->valid()) {
                    $state->exhausted = true;
                    if ($running === 0) {
                        $resolve($this->reorder($keyOrder, $results));
                    }
                }
            };

            Loop::microTask($processNext);
        });

        $concurrentPromise->onCancel(function () use (&$promiseInstances) {
            $this->cancelAll($promiseInstances);
        });

        return $concurrentPromise;
    }

    /**
     * @template TConcurrentSettledValue
     * @param  iterable<int|string, callable(): PromiseInterface<TConcurrentSettledValue>>  $tasks
     * @param  int  $concurrency
     * @return PromiseInterface<array<int|string, SettledResult<TConcurrentSettledValue, mixed>>>
     */
    public function concurrentSettled(iterable $tasks, int $concurrency = 10): PromiseInterface
    {
        /** @var array<int|string, PromiseInterface<TConcurrentSettledValue>> $promiseInstances */
        $promiseInstances = [];

        /** @var Promise<array<int|string, SettledResult<TConcurrentSettledValue, mixed>>> $concurrentPromise */
        $concurrentPromise = new Promise(function (callable $resolve, callable $reject) use ($tasks, $concurrency, &$promiseInstances): void {
            if ($concurrency <= 0) {
                $reject(new InvalidArgumentException('Concurrency limit must be greater than 0'));

                return;
            }

            $iterator = $this->getIterator($tasks);
            $iterator->rewind();

            if (! $iterator->valid()) {
                $resolve([]);

                return;
            }

            $results = [];
            $keyOrder = [];
            $running = 0;
            $state = (object) ['exhausted' => false, 'scheduled' => false];

            $resolveOrdered = function () use (&$results, &$keyOrder, $resolve): void {
                $resolve($this->reorder($keyOrder, $results));
            };

            $processNext = function () use (
                &$processNext,
                $iterator,
                &$running,
                &$results,
                &$keyOrder,
                $state,
                &$promiseInstances,
                $concurrency,
                $resolveOrdered,
            ): void {
                $state->scheduled = false;
                while ($running < $concurrency && $iterator->valid()) {
                    $key = $iterator->key();
                    $task = $iterator->current();

                    $keyOrder[] = $key;
                    $iterator->next();
                    $running++;

                    try {
                        $promise = $task();

                        if (! $promise instanceof PromiseInterface) {
                            $running--;
                            $results[$key] = SettledResult::rejected(new RuntimeException("Invalid task at $key"));
                            if ($state->exhausted && $running === 0) {
                                $resolveOrdered();
                            }

                            continue;
                        }

                        if ($promise->isCancelled()) {
                            $running--;
                            $results[$key] = SettledResult::cancelled();
                            if ($state->exhausted && $running === 0) {
                                $resolveOrdered();
                            }

                            continue;
                        }

                        $promiseInstances[$key] = $promise;

                        $promise->onCancel(function () use ($key, &$results, &$running, $state, $resolveOrdered): void {
                            $results[$key] = SettledResult::cancelled();
                            $running--;
                            if ($state->exhausted && $running === 0) {
                                $resolveOrdered();
                            }
                        });

                        $promise->then(
                            function ($result) use ($key, &$results, &$running, $state, $resolveOrdered, $processNext): void {
                                $results[$key] = SettledResult::fulfilled($result);
                                $running--;
                                if ($state->exhausted && $running === 0) {
                                    $resolveOrdered();
                                } elseif (! $state->scheduled) {
                                    $state->scheduled = true;
                                    Loop::microTask($processNext);
                                }
                            },
                            function ($error) use ($key, &$results, &$running, &$promiseInstances, $state, $resolveOrdered, $processNext): void {
                                $isCancelled = ($promiseInstances[$key] ?? null)?->isCancelled() ?? false;
                                $results[$key] = $isCancelled ? SettledResult::cancelled() : SettledResult::rejected($error);
                                $running--;
                                if ($state->exhausted && $running === 0) {
                                    $resolveOrdered();
                                } elseif (! $state->scheduled) {
                                    $state->scheduled = true;
                                    Loop::microTask($processNext);
                                }
                            }
                        );
                    } catch (Throwable $e) {
                        $running--;
                        $results[$key] = SettledResult::rejected($e);
                        if ($state->exhausted && $running === 0) {
                            $resolveOrdered();
                        }
                    }
                }

                if (! $iterator->valid()) {
                    $state->exhausted = true;
                    if ($running === 0) {
                        $resolveOrdered();
                    }
                }
            };

            Loop::microTask($processNext);
        });

        $concurrentPromise->onCancel(function () use (&$promiseInstances) {
            $this->cancelAll($promiseInstances);
        });

        return $concurrentPromise;
    }

    /**
     * @template TBatchValue
     * @param  iterable<int|string, callable(): PromiseInterface<TBatchValue>>  $tasks
     * @param  int  $batchSize
     * @param  int|null  $concurrency
     * @return PromiseInterface<array<int|string, TBatchValue>>
     */
    public function batch(iterable $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface
    {
        /** @var Promise<array<int|string, TBatchValue>> */
        return new Promise(function (callable $resolve, callable $reject) use ($tasks, $batchSize, $concurrency): void {
            if ($batchSize <= 0) {
                $reject(new InvalidArgumentException('Batch size must be greater than 0'));

                return;
            }

            $iterator = $this->getIterator($tasks);
            $iterator->rewind();

            $allResults = [];
            $concurrency ??= $batchSize;

            $processNextBatch = function () use (&$processNextBatch, $iterator, &$allResults, $batchSize, $concurrency, $resolve, $reject): void {
                if (! $iterator->valid()) {
                    $resolve($allResults);

                    return;
                }

                $batchTasks = [];
                for ($i = 0; $i < $batchSize && $iterator->valid(); $i++) {
                    $batchTasks[$iterator->key()] = $iterator->current();
                    $iterator->next();
                }

                $this->concurrent($batchTasks, $concurrency)
                    ->then(function ($batchResults) use (&$allResults, $processNextBatch): void {
                        foreach ($batchResults as $key => $result) {
                            $allResults[$key] = $result;
                        }
                        unset($batchResults);
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
     * @param  iterable<int|string, callable(): PromiseInterface<TBatchSettledValue>>  $tasks
     * @param  int  $batchSize
     * @param  int|null  $concurrency
     * @return PromiseInterface<array<int|string, SettledResult<TBatchSettledValue, mixed>>>
     */
    public function batchSettled(iterable $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface
    {
        /** @var Promise<array<int|string, SettledResult<TBatchSettledValue, mixed>>> */
        return new Promise(function (callable $resolve, callable $reject) use ($tasks, $batchSize, $concurrency): void {
            if ($batchSize <= 0) {
                $reject(new InvalidArgumentException('Batch size must be greater than 0'));

                return;
            }

            $iterator = $this->getIterator($tasks);
            $iterator->rewind();

            $allResults = [];
            $concurrency ??= $batchSize;

            $processNextBatch = function () use (&$processNextBatch, $iterator, &$allResults, $batchSize, $concurrency, $resolve): void {
                if (! $iterator->valid()) {
                    $resolve($allResults);

                    return;
                }

                $batchTasks = [];
                for ($i = 0; $i < $batchSize && $iterator->valid(); $i++) {
                    $batchTasks[$iterator->key()] = $iterator->current();
                    $iterator->next();
                }

                $this->concurrentSettled($batchTasks, $concurrency)
                    ->then(function ($batchResults) use (&$allResults, $processNextBatch): void {
                        foreach ($batchResults as $key => $result) {
                            $allResults[$key] = $result;
                        }
                        unset($batchResults);
                        Loop::microTask($processNextBatch);
                    })
                ;
            };

            Loop::microTask($processNextBatch);
        });
    }

    /**
     * @template TMapItem
     * @template TMapResult
     *
     * @param iterable<int|string, TMapItem> $items
     * @param callable(TMapItem, int|string): (TMapResult|PromiseInterface<TMapResult>) $mapper
     * @param int|null $concurrency
     *
     * @return PromiseInterface<array<int|string, TMapResult>>
     */
    public function map(iterable $items, callable $mapper, ?int $concurrency = null): PromiseInterface
    {
        $tasks = (function () use ($items, $mapper) {
            foreach ($items as $key => $item) {
                yield $key => function () use ($item, $mapper, $key) {
                    $inputPromise = $item instanceof PromiseInterface
                        ? $item
                        : Promise::resolved($item);

                    return $inputPromise->then(fn ($resolvedValue) => $mapper($resolvedValue, $key));
                };
            }
        })();

        return $this->concurrent($tasks, $concurrency ?? PHP_INT_MAX);
    }

    /**
     * Normalizes an iterable into a manual Iterator without materializing it.
     *
     * @param  iterable<int|string, callable(): PromiseInterface<mixed>>  $tasks
     * @return \Iterator<int|string, callable(): PromiseInterface<mixed>>
     */
    private function getIterator(iterable $tasks): \Iterator
    {
        if ($tasks instanceof \Iterator) {
            return $tasks;
        }
        if ($tasks instanceof \IteratorAggregate) {
            return $tasks->getIterator();
        }

        return (fn () => yield from $tasks)();
    }

    /**
     * Reorders the results array based on the sequence of keys encountered in the iterator.
     *
     * @param  array<int, int|string>  $keyOrder
     * @param  array<int|string, mixed>  $results
     * @return array<int|string, mixed>
     */
    private function reorder(array $keyOrder, array $results): array
    {
        $ordered = [];
        foreach ($keyOrder as $k) {
            $ordered[$k] = $results[$k];
        }

        return $ordered;
    }

    /**
     * @param  array<int|string, PromiseInterface<mixed>>  $promiseInstances
     * @return void
     */
    private function cancelAll(array $promiseInstances): void
    {
        foreach ($promiseInstances as $promise) {
            $promise->cancelChain();
        }
    }
}
