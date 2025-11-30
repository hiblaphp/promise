<?php

namespace Hibla\Promise\Handlers;

use Hibla\Promise\Exceptions\AggregateErrorException;
use Hibla\Promise\Exceptions\TimeoutException;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use InvalidArgumentException;

final readonly class PromiseCollectionHandler
{
    /**
     * @template TAllValue
     * @param  array<int|string, PromiseInterface<TAllValue>>  $promises  Array of PromiseInterface instances.
     * @return PromiseInterface<array<int|string, TAllValue>> A promise that resolves with an array of results.
     */
    public function all(array $promises): PromiseInterface
    {
        /** @var Promise<array<int|string, TAllValue>> */
        return new Promise(function (callable $resolve, callable $reject) use ($promises): void {
            if ($promises === []) {
                $resolve([]);

                return;
            }

            $originalKeys = array_keys($promises);
            $shouldPreserveKeys = $this->shouldPreserveKeys($promises);

            // Pre-initialize results array to maintain order
            $results = [];
            if ($shouldPreserveKeys) {
                foreach ($originalKeys as $key) {
                    $results[$key] = null;
                }
            } else {
                $results = array_fill(0, \count($promises), null);
            }

            $completed = 0;
            $total = \count($promises);

            foreach ($promises as $index => $promise) {
                if (! $this->validatePromiseInstance($promise, $index, [], $reject)) {
                    return;
                }

                $resultIndex = $shouldPreserveKeys ? $index : array_search($index, $originalKeys, true);

                $promise
                    ->then(function ($value) use (&$results, &$completed, $total, $index, $resultIndex, $resolve, $shouldPreserveKeys): void {
                        if ($shouldPreserveKeys) {
                            $results[$index] = $value;
                        } else {
                            $results[$resultIndex] = $value;
                        }

                        $completed++;
                        if ($completed === $total) {
                            $resolve($results);
                        }
                    })
                    ->catch(function ($reason) use ($reject): void {
                        $reject($reason);
                    })
                ;
            }
        });
    }

    /**
     * @template TAllSettledValue
     * @param  array<int|string, PromiseInterface<TAllSettledValue>>  $promises
     * @return PromiseInterface<array<int|string, array{status: 'fulfilled'|'rejected', value?: TAllSettledValue, reason?: mixed}>>
     */
    public function allSettled(array $promises): PromiseInterface
    {
        /** @var Promise<array<int|string, array{status: 'fulfilled'|'rejected', value?: TAllSettledValue, reason?: mixed}>> */
        return new Promise(function (callable $resolve) use ($promises): void {
            if ($promises === []) {
                $resolve([]);

                return;
            }

            $originalKeys = array_keys($promises);
            $shouldPreserveKeys = $this->shouldPreserveKeys($promises);

            // Pre-initialize results array to maintain order
            $results = [];
            if ($shouldPreserveKeys) {
                foreach ($originalKeys as $key) {
                    $results[$key] = null;
                }
            } else {
                $results = array_fill(0, count($promises), null);
            }

            $completed = 0;
            $total = \count($promises);

            foreach ($promises as $index => $promise) {
                $resultIndex = $shouldPreserveKeys ? $index : array_search($index, $originalKeys, true);

                if (! ($promise instanceof PromiseInterface)) {
                    if ($shouldPreserveKeys) {
                        $results[$index] = [
                            'status' => 'rejected',
                            'reason' => new InvalidArgumentException(
                                \sprintf(
                                    'Item at index "%s" must be a PromiseInterface, %s given',
                                    $index,
                                    get_debug_type($promise)
                                )
                            ),
                        ];
                    } else {
                        $results[$resultIndex] = [
                            'status' => 'rejected',
                            'reason' => new InvalidArgumentException(
                                \sprintf(
                                    'Item at index "%s" must be a PromiseInterface, %s given',
                                    $index,
                                    get_debug_type($promise)
                                )
                            ),
                        ];
                    }

                    $completed++;

                    if ($completed === $total) {
                        $resolve($results);
                    }

                    continue;
                }

                $promise
                    ->then(function ($value) use (&$results, &$completed, $total, $index, $resultIndex, $resolve, $shouldPreserveKeys): void {
                        if ($shouldPreserveKeys) {
                            $results[$index] = [
                                'status' => 'fulfilled',
                                'value' => $value,
                            ];
                        } else {
                            $results[$resultIndex] = [
                                'status' => 'fulfilled',
                                'value' => $value,
                            ];
                        }

                        $completed++;

                        if ($completed === $total) {
                            $resolve($results);
                        }
                    })
                    ->catch(function ($reason) use (&$results, &$completed, $total, $index, $resultIndex, $resolve, $shouldPreserveKeys): void {
                        if ($shouldPreserveKeys) {
                            $results[$index] = [
                                'status' => 'rejected',
                                'reason' => $reason,
                            ];
                        } else {
                            $results[$resultIndex] = [
                                'status' => 'rejected',
                                'reason' => $reason,
                            ];
                        }

                        $completed++;

                        if ($completed === $total) {
                            $resolve($results);
                        }
                    })
                ;
            }
        });
    }

    /**
     * @template TRaceValue
     * @param  array<int|string, PromiseInterface<TRaceValue>>  $promises  Array of PromiseInterface instances.
     * @return CancellablePromiseInterface<TRaceValue> A promise that settles with the first settled promise.
     */
    public function race(array $promises): CancellablePromiseInterface
    {
        /** @var array<int|string, PromiseInterface<mixed>> $promiseInstances */
        $promiseInstances = [];
        $settled = false;

        /**
         * @var CancellablePromise<TRaceValue> $cancellablePromise
         */
        $cancellablePromise = new CancellablePromise(
            /** @param callable(TRaceValue): void $resolve */
            function (callable $resolve, callable $reject) use ($promises, &$promiseInstances, &$settled): void {
                if ($promises === []) {
                    $reject(new InvalidArgumentException('Cannot race with no promises provided'));

                    return;
                }

                foreach ($promises as $index => $promise) {
                    if (! $this->validatePromiseInstance($promise, $index, $promiseInstances, $reject)) {
                        return;
                    }

                    $promiseInstances[$index] = $promise;

                    $promise
                        ->then(function ($value) use ($resolve, &$settled, &$promiseInstances, $index): void {
                            if ($settled) {
                                return;
                            }

                            $this->handleRaceSettlement($settled, $promiseInstances, $index);
                            $resolve($value);
                        })
                        ->catch(function ($reason) use ($reject, &$settled, &$promiseInstances, $index): void {
                            if ($settled) {
                                return;
                            }

                            $this->handleRaceSettlement($settled, $promiseInstances, $index);
                            $reject($reason);
                        })
                    ;
                }
            }
        );

        $cancellablePromise->setCancelHandler(function () use (&$promiseInstances, &$settled): void {
            $settled = true;
            foreach ($promiseInstances as $promise) {
                $this->cancelPromiseIfPossible($promise);
            }
        });

        return $cancellablePromise;
    }

    /**
     * @template TTimeoutValue
     * @param  PromiseInterface<TTimeoutValue>  $promise  The promise to add timeout to
     * @param  float  $seconds  Timeout duration in seconds
     * @return CancellablePromiseInterface<TTimeoutValue>
     */
    public function timeout(PromiseInterface $promise, float $seconds): CancellablePromiseInterface
    {
        if ($seconds <= 0) {
            throw new InvalidArgumentException('Timeout must be greater than zero');
        }

        $timeoutPromise = (new TimerHandler())
            ->delay($seconds)
            ->then(fn () => throw new TimeoutException($seconds))
        ;

        return $this->race([$promise, $timeoutPromise]);
    }

    /**
     * @template TAnyValue
     * @param  array<int|string, PromiseInterface<TAnyValue>>  $promises  Array of promises to wait for
     * @return CancellablePromiseInterface<TAnyValue> A promise that resolves with the first settled value
     */
    public function any(array $promises): CancellablePromiseInterface
    {
        /** @var array<int|string, PromiseInterface<mixed>> $promiseInstances */
        $promiseInstances = [];
        $settled = false;

        /** @var CancellablePromise<TAnyValue> $cancellablePromise */
        $cancellablePromise = new CancellablePromise(
            function (callable $resolve, callable $reject) use ($promises, &$promiseInstances, &$settled): void {
                if ($promises === []) {
                    $reject(new AggregateErrorException([], 'No promises provided'));

                    return;
                }

                $rejections = [];
                $rejectedCount = 0;
                $total = \count($promises);

                foreach ($promises as $index => $promise) {
                    if (! $this->validatePromiseInstance($promise, $index, $promiseInstances, $reject)) {
                        return;
                    }

                    $promiseInstances[$index] = $promise;

                    $promise
                        ->then(
                            function ($value) use ($resolve, &$settled, &$promiseInstances, $index): void {
                                if ($settled) {
                                    return;
                                }

                                $this->handleAnySettlement($settled, $promiseInstances, $index);
                                $resolve($value);
                            }
                        )
                        ->catch(
                            function ($reason) use (
                                &$rejections,
                                &$rejectedCount,
                                &$settled,
                                $total,
                                $index,
                                $reject
                            ): void {
                                if ($settled) {
                                    return;
                                }

                                $rejections[$index] = $reason;
                                $rejectedCount++;

                                if ($rejectedCount === $total) {
                                    $settled = true;
                                    $reject(new AggregateErrorException($rejections, 'All promises were rejected'));
                                }
                            }
                        )
                    ;
                }
            }
        );

        $cancellablePromise->setCancelHandler(
            function () use (&$promiseInstances, &$settled): void {
                $settled = true;
                foreach ($promiseInstances as $promise) {
                    $this->cancelPromiseIfPossible($promise);
                }
            }
        );

        return $cancellablePromise;
    }

    /**
     * @param  mixed  $promise  The item to validate
     * @param  int|string  $index  The index/key of the item in the original array
     * @param  array<int|string, PromiseInterface<mixed>>  $promiseInstances  Previously validated promises to cancel on failure
     * @param  callable  $reject  Rejection callback to call on validation failure
     * @return bool True if valid, false if invalid (and rejection was triggered)
     */
    private function validatePromiseInstance(
        mixed $promise,
        int|string $index,
        array $promiseInstances,
        callable $reject
    ): bool {
        if (! ($promise instanceof PromiseInterface)) {
            foreach ($promiseInstances as $p) {
                $this->cancelPromiseIfPossible($p);
            }

            $reject(new InvalidArgumentException(
                \sprintf(
                    'Item at index "%s" must be an instance of PromiseInterface, %s given',
                    $index,
                    get_debug_type($promise)
                )
            ));

            return false;
        }

        return true;
    }

    /**
     * @param  array<int|string, PromiseInterface<mixed>>  $promiseInstances
     */
    private function handleAnySettlement(bool &$settled, array &$promiseInstances, int|string $winnerIndex): void
    {
        $settled = true;

        foreach ($promiseInstances as $index => $promise) {
            if ($index === $winnerIndex) {
                continue;
            }
            $this->cancelPromiseIfPossible($promise);
        }
    }

    /**
     * @param  array<int|string, PromiseInterface<mixed>>  $promiseInstances
     */
    private function handleRaceSettlement(bool &$settled, array &$promiseInstances, int|string $winnerIndex): void
    {
        $settled = true;

        foreach ($promiseInstances as $index => $promise) {
            if ($index === $winnerIndex) {
                continue;
            }
            $this->cancelPromiseIfPossible($promise);
        }
    }

    /**
     * @param  PromiseInterface<mixed>  $promise
     */
    private function cancelPromiseIfPossible(PromiseInterface $promise): void
    {
        if ($promise instanceof CancellablePromise && ! $promise->isCancelled()) {
            $promise->cancel();
        } elseif ($promise instanceof Promise) {
            $rootCancellable = $promise->getRootCancellable();
            if ($rootCancellable !== null && ! $rootCancellable->isCancelled()) {
                $rootCancellable->cancel();
            }
        }
    }

    /**
     * @param  array<int|string, mixed>  $array
     */
    private function shouldPreserveKeys(array $array): bool
    {
        $keys = array_keys($array);

        // If any key is a string, preserve keys
        if (\count(array_filter($keys, 'is_string')) > 0) {
            return true;
        }

        // If numeric keys are not sequential starting from 0, preserve them
        $expectedKeys = range(0, \count($array) - 1);

        return $keys !== $expectedKeys;
    }
}
