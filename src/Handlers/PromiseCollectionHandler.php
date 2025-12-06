<?php

declare(strict_types=1);

namespace Hibla\Promise\Handlers;

use Hibla\Promise\Exceptions\AggregateErrorException;
use Hibla\Promise\Exceptions\TimeoutException;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use InvalidArgumentException;

final readonly class PromiseCollectionHandler
{
    /**
     * @template TAllValue
     * @param  array<int|string, PromiseInterface<TAllValue>>  $promises 
     * @return PromiseInterface<array<int|string, TAllValue>>
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
     * @param  array<int|string, PromiseInterface<TRaceValue>>  $promises 
     * @return PromiseInterface<TRaceValue>
     */
    public function race(array $promises): PromiseInterface
    {
        /** @var array<int|string, PromiseInterface<mixed>> $promiseInstances */
        $promiseInstances = [];
        $settled = false;

        /**
         * @var Promise<TRaceValue> $racePromise
         */
        $racePromise = new Promise(
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

        $racePromise->setCancelHandler(function () use (&$promiseInstances, &$settled): void {
            $settled = true;
            foreach ($promiseInstances as $promise) {
                $this->cancelPromiseAndParents($promise);
            }
        });

        return $racePromise;
    }

    /**
     * {@inheritdoc}
     * @template TTimeoutValue
     * @param  PromiseInterface<TTimeoutValue>  $promise 
     * @param  float  $seconds 
     * @return PromiseInterface<TTimeoutValue>
     */
    public function timeout(PromiseInterface $promise, float $seconds): PromiseInterface
    {
        if ($seconds <= 0) {
            throw new InvalidArgumentException('Timeout must be greater than zero');
        }

        $timeoutPromise = (new TimerHandler())
            ->delay($seconds)
            ->then(fn() => throw new TimeoutException($seconds));

        return $this->race([$promise, $timeoutPromise]);
    }

    /**
     * @template TAnyValue
     * @param  array<int|string, PromiseInterface<TAnyValue>>  $promises  Array of promises to wait for
     * @return PromiseInterface<TAnyValue> A promise that resolves with the first settled value
     */
    public function any(array $promises): PromiseInterface
    {
        /** @var array<int|string, PromiseInterface<mixed>> $promiseInstances */
        $promiseInstances = [];
        $settled = false;

        /** @var Promise<TAnyValue> $anyPromise */
        $anyPromise = new Promise(
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

        $anyPromise->setCancelHandler(
            function () use (&$promiseInstances, &$settled): void {
                $settled = true;
                foreach ($promiseInstances as $promise) {
                    $this->cancelPromiseAndParents($promise);
                }
            }
        );

        return $anyPromise;
    }

    /**
     * @param  mixed  $promise  
     * @param  int|string  $index 
     * @param  array<int|string, PromiseInterface<mixed>>  $promiseInstances  
     * @param  callable  $reject 
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
                $p->cancel();
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
     * @param  bool  $settled
     * @param  array<int|string, PromiseInterface<mixed>>  $promiseInstances
     * @param  int|string  $winnerIndex
     * @return void
     */
    private function handleAnySettlement(bool &$settled, array &$promiseInstances, int|string $winnerIndex): void
    {
        $settled = true;

        foreach ($promiseInstances as $index => $promise) {
            if ($index === $winnerIndex) {
                continue;
            }
            $this->cancelPromiseAndParents($promise);
        }
    }

    /**
     * @param  bool  $settled
     * @param  array<int|string, PromiseInterface<mixed>>  $promiseInstances
     * @param  int|string  $winnerIndex
     * @return void
     */
    private function handleRaceSettlement(bool &$settled, array &$promiseInstances, int|string $winnerIndex): void
    {
        $settled = true;

        foreach ($promiseInstances as $index => $promise) {
            if ($index === $winnerIndex) {
                continue;
            }
            $this->cancelPromiseAndParents($promise);
        }
    }

    /**
     * Cancel a promise and recursively cancel its parent promise (backward propagation).
     * This is specifically for race/any scenarios where we need to cancel
     * the underlying promise that a child was created from via then().
     * 
     * Only used in race() and any() - not in general cancel flow.
     * This ensures that when we cancel a child promise created by then(),
     * we also cancel the parent delay/timer promise that it was chained from.
     *
     * @param  PromiseInterface<mixed>  $promise  The promise to cancel
     */
    private function cancelPromiseAndParents(PromiseInterface $promise): void
    {
        if ($promise->isCancelled()) {
            return;
        }

        if ($promise instanceof Promise) {
            // Access private property via closure binding
            $getParent = function () {
                return $this->parentPromise; // @phpstan-ignore-line This property is called in a closure binding to avoid polluting promise class with getters
            };

            /** @var Promise<mixed>|null $parent */
            $parent = $getParent->call($promise);

            if ($parent !== null && !$parent->isCancelled()) {
                $this->cancelPromiseAndParents($parent);
            }
        }

        $promise->cancel();
    }

    /**
     * @param  array<int|string, mixed>  $array
     * @return bool
     */
    private function shouldPreserveKeys(array $array): bool
    {
        $keys = array_keys($array);

        if (\count(array_filter($keys, 'is_string')) > 0) {
            return true;
        }

        $expectedKeys = range(0, \count($array) - 1);

        return $keys !== $expectedKeys;
    }
}
