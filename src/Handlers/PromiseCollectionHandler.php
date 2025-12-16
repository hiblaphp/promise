<?php

declare(strict_types=1);

namespace Hibla\Promise\Handlers;

use Hibla\Promise\Exceptions\AggregateErrorException;
use Hibla\Promise\Exceptions\TimeoutException;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Promise\SettledResult;
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
            $results = $this->initializeResultsArray($shouldPreserveKeys, $originalKeys, \count($promises));

            $completed = 0;
            $total = \count($promises);
            $isRejected = false;

            foreach ($promises as $index => $promise) {
                if (! $this->validatePromiseInstance($promise, $index, [], $reject)) {
                    return;
                }

                if ($promise->isCancelled()) {
                    $reject(new \Hibla\Promise\Exceptions\PromiseCancelledException(
                        \sprintf('Promise at index "%s" was cancelled', $index)
                    ));

                    return;
                }
            }

            foreach ($promises as $index => $promise) {
                $resultIndex = $shouldPreserveKeys ? $index : array_search($index, $originalKeys, true);

                $promise
                    ->then(function ($value) use (&$results, &$completed, &$isRejected, $total, $index, $resultIndex, $resolve, $shouldPreserveKeys): void {
                        if ($isRejected) {
                            return;
                        }

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
                    ->catch(function ($reason) use (&$isRejected, $reject): void {
                        if ($isRejected) {
                            return;
                        }
                        $isRejected = true;
                        $reject($reason);
                    })
                ;
            }
        });
    }

    /**
     * @template TAllSettledValue
     * @param array<int|string, PromiseInterface<TAllSettledValue>> $promises
     * @return PromiseInterface<array<int|string, SettledResult<TAllSettledValue, mixed>>>
     */
    public function allSettled(array $promises): PromiseInterface
    {
        /** @var Promise<array<int|string, SettledResult<TAllSettledValue, mixed>>> */
        return new Promise(function (callable $resolve) use ($promises): void {
            if ($promises === []) {
                $resolve([]);

                return;
            }

            $originalKeys = array_keys($promises);
            $shouldPreserveKeys = $this->shouldPreserveKeys($promises);
            $results = $this->initializeResultsArray(
                $shouldPreserveKeys,
                $originalKeys,
                \count($promises)
            );

            $completed = 0;
            $total = \count($promises);

            foreach ($promises as $index => $promise) {
                $resultIndex = $shouldPreserveKeys
                    ? $index
                    : array_search($index, $originalKeys, true);

                $key = $shouldPreserveKeys ? $index : $resultIndex;

                if (! ($promise instanceof PromiseInterface)) {
                    $results[$key] = SettledResult::rejected(
                        new InvalidArgumentException(
                            \sprintf(
                                'Item at index "%s" must be a PromiseInterface, %s given',
                                $index,
                                get_debug_type($promise)
                            )
                        )
                    );

                    if (++$completed === $total) {
                        $resolve($results);
                    }

                    continue;
                }

                if ($promise->isCancelled()) {
                    $results[$key] = SettledResult::cancelled();

                    if (++$completed === $total) {
                        $resolve($results);
                    }

                    continue;
                }

                $promise
                    ->then(function ($value) use (
                        &$results,
                        &$completed,
                        $total,
                        $key,
                        $resolve
                    ): void {
                        $results[$key] = SettledResult::fulfilled($value);

                        if (++$completed === $total) {
                            $resolve($results);
                        }
                    })
                    ->catch(function ($reason) use (
                        &$results,
                        &$completed,
                        $total,
                        $key,
                        $resolve,
                        $promise
                    ): void {
                        if ($promise->isCancelled()) {
                            $results[$key] = SettledResult::cancelled();
                        } else {
                            $results[$key] = SettledResult::rejected($reason);
                        }

                        if (++$completed === $total) {
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

                            $this->handleWinnerSettlement($settled, $promiseInstances, $index);
                            $resolve($value);
                        })
                        ->catch(function ($reason) use ($reject, &$settled, &$promiseInstances, $index): void {
                            if ($settled) {
                                return;
                            }

                            $this->handleWinnerSettlement($settled, $promiseInstances, $index);
                            $reject($reason);
                        })
                    ;
                }
            }
        );

        $racePromise->onCancel(function () use (&$promiseInstances, &$settled): void {
            $settled = true;
            foreach ($promiseInstances as $promise) {
                $promise->cancelChain();
            }
        });

        return $racePromise;
    }

    /**
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
            ->then(fn () => throw new TimeoutException($seconds))
        ;

        return $this->race([$promise, $timeoutPromise]);
    }

    /**
     * @template TAnyValue
     * @param  array<int|string, PromiseInterface<TAnyValue>>  $promises
     * @return PromiseInterface<TAnyValue>
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

                                $this->handleWinnerSettlement($settled, $promiseInstances, $index);
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

        $anyPromise->onCancel(
            function () use (&$promiseInstances, &$settled): void {
                $settled = true;
                foreach ($promiseInstances as $promise) {
                    $promise->cancelChain();
                }
            }
        );

        return $anyPromise;
    }

    /**
     * @param  bool  $shouldPreserveKeys
     * @param  array<int|string>  $originalKeys
     * @param  int  $total
     * @return array<int|string, mixed>
     */
    private function initializeResultsArray(bool $shouldPreserveKeys, array $originalKeys, int $total): array
    {
        return $shouldPreserveKeys
            ? array_fill_keys($originalKeys, null)
            : array_fill(0, $total, null);
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
    private function handleWinnerSettlement(bool &$settled, array &$promiseInstances, int|string $winnerIndex): void
    {
        $settled = true;

        foreach ($promiseInstances as $index => $promise) {
            if ($index === $winnerIndex) {
                continue;
            }

            $promise->cancelChain();
        }
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
