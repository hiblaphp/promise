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

            try {
                $iterator = $this->getIterator($tasks);
                $iterator->rewind();
                if (! $iterator->valid()) {
                    $resolve([]);

                    return;
                }
            } catch (Throwable $e) {
                $reject($e);

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

                try {
                    while ($running < $concurrency && $iterator->valid()) {
                        $key = $iterator->key();
                        $task = $iterator->current();

                        $keyOrder[] = $key;
                        $iterator->next();
                        $running++;

                        try {
                            $promise = $task();

                            if (! $promise instanceof PromiseInterface) {
                                throw new RuntimeException(sprintf(
                                    'Task at key "%s" must return a PromiseInterface, %s given',
                                    $key,
                                    get_debug_type($promise)
                                ));
                            }

                            if ($promise->isCancelled()) {
                                $state->isRejected = true;
                                $this->cancelAll($promiseInstances);
                                $reject(new CancelledException(sprintf('Promise at key "%s" was cancelled', $key)));

                                return;
                            }

                            $promiseInstances[$key] = $promise;

                            $promise->onCancel(function () use ($key, $state, &$promiseInstances, $reject): void {
                                if ($state->isRejected) {
                                    return;
                                }
                                $state->isRejected = true;
                                $this->cancelAll($promiseInstances);
                                $reject(new CancelledException(sprintf('Promise at key "%s" was cancelled', $key)));
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
                } catch (Throwable $e) {
                    // Catch synchronous iterator errors (e.g. Generator throwing exception)
                    $state->isRejected = true;
                    $this->cancelAll($promiseInstances);
                    $reject($e);
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

            try {
                $iterator = $this->getIterator($tasks);
                $iterator->rewind();
                if (! $iterator->valid()) {
                    $resolve([]);

                    return;
                }
            } catch (Throwable $e) {
                $reject($e);

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
                $reject // We need reject here now for iterator failures
            ): void {
                $state->scheduled = false;

                try {
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
                } catch (Throwable $e) {
                    // Critical failure in the iterator itself
                    $this->cancelAll($promiseInstances);
                    $reject($e);
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
        /** @var PromiseInterface<array<int|string, TBatchValue>>|null $currentBatchPromise */
        $currentBatchPromise = null;

        /** @var Promise<array<int|string, TBatchValue>> $batchPromise */
        $batchPromise = new Promise(function (callable $resolve, callable $reject) use ($tasks, $batchSize, $concurrency, &$currentBatchPromise): void {
            if ($batchSize <= 0) {
                $reject(new InvalidArgumentException('Batch size must be greater than 0'));

                return;
            }

            try {
                $iterator = $this->getIterator($tasks);
                $iterator->rewind();
            } catch (Throwable $e) {
                $reject($e);

                return;
            }

            $allResults = [];
            $concurrency ??= $batchSize;

            $processNextBatch = function () use (&$processNextBatch, $iterator, &$allResults, $batchSize, $concurrency, $resolve, $reject, &$currentBatchPromise): void {
                try {
                    if (! $iterator->valid()) {
                        $resolve($allResults);

                        return;
                    }

                    $batchTasks = [];
                    for ($i = 0; $i < $batchSize && $iterator->valid(); $i++) {
                        $batchTasks[$iterator->key()] = $iterator->current();
                        $iterator->next();
                    }
                } catch (Throwable $e) {
                    $reject($e);

                    return;
                }

                $currentBatchPromise = $this->concurrent($batchTasks, $concurrency);
                $currentBatchPromise
                    ->then(function ($batchResults) use (&$allResults, $processNextBatch, &$currentBatchPromise): void {
                        $currentBatchPromise = null;

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

        $batchPromise->onCancel(function () use (&$currentBatchPromise): void {
            $currentBatchPromise?->cancel();
        });

        return $batchPromise;
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
        /** @var PromiseInterface<array<int|string, SettledResult<TBatchSettledValue, mixed>>>|null $currentBatchPromise */
        $currentBatchPromise = null;

        /** @var Promise<array<int|string, SettledResult<TBatchSettledValue, mixed>>> $batchPromise */
        $batchPromise = new Promise(function (callable $resolve, callable $reject) use ($tasks, $batchSize, $concurrency, &$currentBatchPromise): void {
            if ($batchSize <= 0) {
                $reject(new InvalidArgumentException('Batch size must be greater than 0'));

                return;
            }

            try {
                $iterator = $this->getIterator($tasks);
                $iterator->rewind();
            } catch (Throwable $e) {
                $reject($e);

                return;
            }

            $allResults = [];
            $concurrency ??= $batchSize;

            $processNextBatch = function () use (&$processNextBatch, $iterator, &$allResults, $batchSize, $concurrency, $resolve, $reject, &$currentBatchPromise): void {
                try {
                    if (! $iterator->valid()) {
                        $resolve($allResults);

                        return;
                    }

                    $batchTasks = [];
                    for ($i = 0; $i < $batchSize && $iterator->valid(); $i++) {
                        $batchTasks[$iterator->key()] = $iterator->current();
                        $iterator->next();
                    }
                } catch (Throwable $e) {
                    $reject($e);

                    return;
                }

                $currentBatchPromise = $this->concurrentSettled($batchTasks, $concurrency);
                $currentBatchPromise
                    ->then(function ($batchResults) use (&$allResults, $processNextBatch, &$currentBatchPromise): void {
                        $currentBatchPromise = null;

                        foreach ($batchResults as $key => $result) {
                            $allResults[$key] = $result;
                        }
                        unset($batchResults);
                        Loop::microTask($processNextBatch);
                    });
            };

            Loop::microTask($processNextBatch);
        });

        $batchPromise->onCancel(function () use (&$currentBatchPromise): void {
            $currentBatchPromise?->cancel();
        });

        return $batchPromise;
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

                    return $inputPromise->then(fn($resolvedValue) => $mapper($resolvedValue, $key));
                };
            }
        })();

        return $this->concurrent($tasks, $concurrency ?? PHP_INT_MAX);
    }

    /**
     * @template TMapItem
     * @template TMapResult
     *
     * @param iterable<int|string, TMapItem> $items
     * @param callable(TMapItem, int|string): (TMapResult|PromiseInterface<TMapResult>) $mapper
     * @param int|null $concurrency
     *
     * @return PromiseInterface<array<int|string, SettledResult<TMapResult, mixed>>>
     */
    public function mapSettled(iterable $items, callable $mapper, ?int $concurrency = null): PromiseInterface
    {
        $tasks = (function () use ($items, $mapper) {
            foreach ($items as $key => $item) {
                yield $key => function () use ($item, $mapper, $key) {
                    $inputPromise = $item instanceof PromiseInterface
                        ? $item
                        : Promise::resolved($item);

                    return $inputPromise->then(fn($resolvedValue) => $mapper($resolvedValue, $key));
                };
            }
        })();

        return $this->concurrentSettled($tasks, $concurrency ?? PHP_INT_MAX);
    }

    /**
     * @template TForEachItem
     *
     * @param iterable<int|string, TForEachItem> $items
     * @param callable(TForEachItem, int|string): (void|PromiseInterface<void>) $callback
     * @param int|null $concurrency
     *
     * @return PromiseInterface<void>
     */
    public function forEach(iterable $items, callable $callback, ?int $concurrency = null): PromiseInterface
    {
        /** @var array<int|string, PromiseInterface<mixed>> $promiseInstances */
        $promiseInstances = [];

        /** @var Promise<void> $forEachPromise */
        $forEachPromise = new Promise(function (callable $resolve, callable $reject) use ($items, $callback, $concurrency, &$promiseInstances): void {
            $concurrency ??= PHP_INT_MAX;

            if ($concurrency <= 0) {
                $reject(new InvalidArgumentException('Concurrency limit must be greater than 0'));

                return;
            }

            try {
                $iterator = $this->getIterator(
                    (function () use ($items, $callback) {
                        foreach ($items as $key => $item) {
                            yield $key => function () use ($item, $callback, $key) {
                                $inputPromise = $item instanceof PromiseInterface
                                    ? $item
                                    : Promise::resolved($item);

                                return $inputPromise->then(fn($resolvedValue) => $callback($resolvedValue, $key));
                            };
                        }
                    })()
                );

                $iterator->rewind();

                if (! $iterator->valid()) {
                    $resolve(null);

                    return;
                }
            } catch (Throwable $e) {
                $reject($e);

                return;
            }

            $running = 0;
            $state = (object) ['isRejected' => false, 'exhausted' => false, 'scheduled' => false];

            $processNext = function () use (
                &$processNext,
                $iterator,
                &$running,
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

                try {
                    while ($running < $concurrency && $iterator->valid()) {
                        $key = $iterator->key();
                        $task = $iterator->current();
                        $iterator->next();
                        $running++;

                        try {
                            $promise = $task();

                            if (! $promise instanceof PromiseInterface) {
                                throw new RuntimeException(sprintf(
                                    'Task at key "%s" must return a PromiseInterface, %s given',
                                    $key,
                                    get_debug_type($promise)
                                ));
                            }

                            if ($promise->isCancelled()) {
                                $state->isRejected = true;
                                $this->cancelAll($promiseInstances);
                                $reject(new CancelledException(sprintf('Promise at key "%s" was cancelled', $key)));

                                return;
                            }

                            $promiseInstances[$key] = $promise;

                            $promise->onCancel(function () use ($key, $state, &$promiseInstances, $reject): void {
                                if ($state->isRejected) {
                                    return;
                                }
                                $state->isRejected = true;
                                $this->cancelAll($promiseInstances);
                                $reject(new CancelledException(sprintf('Promise at key "%s" was cancelled', $key)));
                            });
                        } catch (Throwable $e) {
                            $state->isRejected = true;
                            $this->cancelAll($promiseInstances);
                            $reject($e);

                            return;
                        }

                        $promise->then(
                            function () use ($key, &$running, &$promiseInstances, $state, $resolve, $processNext): void {
                                if ($state->isRejected) {
                                    return;
                                }

                                unset($promiseInstances[$key]);
                                $running--;

                                if ($state->exhausted && $running === 0) {
                                    $resolve(null);
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
                            $resolve(null);
                        }
                    }
                } catch (Throwable $e) {
                    $state->isRejected = true;
                    $this->cancelAll($promiseInstances);
                    $reject($e);
                }
            };

            Loop::microTask($processNext);
        });

        $forEachPromise->onCancel(function () use (&$promiseInstances): void {
            $this->cancelAll($promiseInstances);
        });

        return $forEachPromise;
    }

    /**
     * @template TForEachItem
     *
     * @param iterable<int|string, TForEachItem> $items
     * @param callable(TForEachItem, int|string): (void|PromiseInterface<void>) $callback
     * @param int|null $concurrency
     *
     * @return PromiseInterface<void>
     */
    public function forEachSettled(iterable $items, callable $callback, ?int $concurrency = null): PromiseInterface
    {
        /** @var array<int|string, PromiseInterface<mixed>> $promiseInstances */
        $promiseInstances = [];

        /** @var Promise<void> $forEachPromise */
        $forEachPromise = new Promise(function (callable $resolve, callable $reject) use ($items, $callback, $concurrency, &$promiseInstances): void {
            $concurrency ??= PHP_INT_MAX;

            if ($concurrency <= 0) {
                $reject(new InvalidArgumentException('Concurrency limit must be greater than 0'));

                return;
            }

            try {
                $iterator = $this->getIterator(
                    (function () use ($items, $callback) {
                        foreach ($items as $key => $item) {
                            yield $key => function () use ($item, $callback, $key) {
                                $inputPromise = $item instanceof PromiseInterface
                                    ? $item
                                    : Promise::resolved($item);

                                return $inputPromise->then(fn($resolvedValue) => $callback($resolvedValue, $key));
                            };
                        }
                    })()
                );

                $iterator->rewind();

                if (! $iterator->valid()) {
                    $resolve(null);

                    return;
                }
            } catch (Throwable $e) {
                $reject($e);

                return;
            }

            $running = 0;
            $state = (object) ['exhausted' => false, 'scheduled' => false];

            $processNext = function () use (
                &$processNext,
                $iterator,
                &$running,
                $state,
                &$promiseInstances,
                $concurrency,
                $resolve,
                $reject,
            ): void {
                $state->scheduled = false;

                try {
                    while ($running < $concurrency && $iterator->valid()) {
                        $key = $iterator->key();
                        $task = $iterator->current();
                        $iterator->next();
                        $running++;

                        $settle = function () use ($key, &$running, &$promiseInstances, $state, $resolve, $processNext): void {
                            unset($promiseInstances[$key]);
                            $running--;

                            if ($state->exhausted && $running === 0) {
                                $resolve(null);
                            } elseif (! $state->scheduled) {
                                $state->scheduled = true;
                                Loop::microTask($processNext);
                            }
                        };

                        try {
                            $promise = $task();

                            if (! $promise instanceof PromiseInterface || $promise->isCancelled()) {
                                $running--;
                                if ($state->exhausted && $running === 0) {
                                    $resolve(null);
                                }

                                continue;
                            }

                            $promiseInstances[$key] = $promise;
                            $promise->onCancel($settle);
                            $promise->then($settle, $settle);
                        } catch (Throwable) {
                            $running--;
                            if ($state->exhausted && $running === 0) {
                                $resolve(null);
                            }
                        }
                    }

                    if (! $iterator->valid()) {
                        $state->exhausted = true;
                        if ($running === 0) {
                            $resolve(null);
                        }
                    }
                } catch (Throwable $e) {
                    $this->cancelAll($promiseInstances);
                    $reject($e);
                }
            };

            Loop::microTask($processNext);
        });

        $forEachPromise->onCancel(function () use (&$promiseInstances): void {
            $this->cancelAll($promiseInstances);
        });

        return $forEachPromise;
    }

    /**
     * @template TFilterItem
     *
     * @param iterable<int|string, TFilterItem> $items
     * @param callable(TFilterItem, int|string): (bool|PromiseInterface<bool>) $predicate
     * @param int|null $concurrency
     *
     * @return PromiseInterface<array<int|string, TFilterItem>>
     */
    public function filter(iterable $items, callable $predicate, ?int $concurrency = null): PromiseInterface
    {
        /** @var array<int|string, PromiseInterface<mixed>> $promiseInstances */
        $promiseInstances = [];

        /** @var Promise<array<int|string, TFilterItem>> $filterPromise */
        $filterPromise = new Promise(function (callable $resolve, callable $reject) use ($items, $predicate, $concurrency, &$promiseInstances): void {
            $concurrency ??= PHP_INT_MAX;

            if ($concurrency <= 0) {
                $reject(new InvalidArgumentException('Concurrency limit must be greater than 0'));

                return;
            }

            // Snapshot the items since it need both the original value and the predicate result.
            $snapshot = [];
            $tasks = (function () use ($items, $predicate, &$snapshot) {
                foreach ($items as $key => $item) {
                    $snapshot[$key] = $item;

                    yield $key => function () use ($item, $predicate, $key, &$snapshot) {
                        $inputPromise = $item instanceof PromiseInterface
                            ? $item
                            : Promise::resolved($item);

                        return $inputPromise->then(function ($resolvedValue) use ($predicate, $key, &$snapshot) {
                            $snapshot[$key] = $resolvedValue;

                            return $predicate($resolvedValue, $key);
                        });
                    };
                }
            })();

            try {
                $iterator = $this->getIterator($tasks);
                $iterator->rewind();

                if (! $iterator->valid()) {
                    $resolve([]);

                    return;
                }
            } catch (Throwable $e) {
                $reject($e);

                return;
            }

            $predicateResults = [];
            $keyOrder = [];
            $running = 0;
            $state = (object) ['isRejected' => false, 'exhausted' => false, 'scheduled' => false];

            $processNext = function () use (
                &$processNext,
                $iterator,
                &$running,
                &$predicateResults,
                &$keyOrder,
                &$snapshot,
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

                try {
                    while ($running < $concurrency && $iterator->valid()) {
                        $key = $iterator->key();
                        $task = $iterator->current();
                        $iterator->next();
                        $running++;
                        $keyOrder[] = $key;

                        try {
                            $promise = $task();

                            if (! $promise instanceof PromiseInterface) {
                                throw new RuntimeException(sprintf(
                                    'Predicate at key "%s" must return a bool or PromiseInterface, %s given',
                                    $key,
                                    get_debug_type($promise)
                                ));
                            }

                            if ($promise->isCancelled()) {
                                $state->isRejected = true;
                                $this->cancelAll($promiseInstances);
                                $reject(new CancelledException(sprintf('Promise at key "%s" was cancelled', $key)));

                                return;
                            }

                            $promiseInstances[$key] = $promise;

                            $promise->onCancel(function () use ($key, $state, &$promiseInstances, $reject): void {
                                if ($state->isRejected) {
                                    return;
                                }
                                $state->isRejected = true;
                                $this->cancelAll($promiseInstances);
                                $reject(new CancelledException(sprintf('Promise at key "%s" was cancelled', $key)));
                            });
                        } catch (Throwable $e) {
                            $state->isRejected = true;
                            $this->cancelAll($promiseInstances);
                            $reject($e);

                            return;
                        }

                        $promise->then(
                            function (mixed $passed) use ($key, &$running, &$predicateResults, &$keyOrder, &$snapshot, $state, $resolve, $processNext): void {
                                if ($state->isRejected) {
                                    return;
                                }

                                $predicateResults[$key] = (bool) $passed;
                                $running--;

                                if ($state->exhausted && $running === 0) {
                                    $resolve($this->applyFilter($keyOrder, $predicateResults, $snapshot));
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
                            $resolve($this->applyFilter($keyOrder, $predicateResults, $snapshot));
                        }
                    }
                } catch (Throwable $e) {
                    $state->isRejected = true;
                    $this->cancelAll($promiseInstances);
                    $reject($e);
                }
            };

            Loop::microTask($processNext);
        });

        $filterPromise->onCancel(function () use (&$promiseInstances): void {
            $this->cancelAll($promiseInstances);
        });

        return $filterPromise;
    }

    /**
     * Builds the filtered result array in original key order.
     *
     * @param array<int, int|string>       $keyOrder
     * @param array<int|string, bool>      $predicateResults
     * @param array<int|string, mixed>     $snapshot
     * @return array<int|string, mixed>
     */
    private function applyFilter(array $keyOrder, array $predicateResults, array $snapshot): array
    {
        $filtered = [];
        foreach ($keyOrder as $key) {
            if ($predicateResults[$key] ?? false) {
                $filtered[$key] = $snapshot[$key];
            }
        }

        return $filtered;
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

        return (fn() => yield from $tasks)();
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
