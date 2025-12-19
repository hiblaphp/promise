<?php

declare(strict_types=1);

namespace Hibla\Promise\Handlers;

use Hibla\Promise\Exceptions\AggregateErrorException;
use Hibla\Promise\Exceptions\PromiseCancelledException;
use Hibla\Promise\Exceptions\TimeoutException;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Promise\SettledResult;
use InvalidArgumentException;

final readonly class PromiseCollectionHandler
{
    /**
     * @template TAllValue
     * @param  iterable<int|string, PromiseInterface<TAllValue>>  $promises
     * @return PromiseInterface<array<int|string, TAllValue>>
     */
    public function all(iterable $promises): PromiseInterface
    {
        $promises = \is_array($promises) ? $promises : \iterator_to_array($promises);

        /** @var Promise<array<int|string, TAllValue>> */
        return new Promise(function (callable $resolve, callable $reject) use ($promises): void {
            if ($promises === []) {
                $resolve([]);

                return;
            }

            $total = \count($promises);
            $results = \array_fill_keys(\array_keys($promises), null);
            $completed = 0;
            $isSettled = false;

            foreach ($promises as $index => $promise) {
                if (! ($promise instanceof PromiseInterface)) {
                    $isSettled = true;
                    $this->cancelAll($promises);
                    $reject(new InvalidArgumentException(\sprintf('Item at index "%s" must be PromiseInterface', $index)));

                    return;
                }

                if ($promise->isCancelled()) {
                    $isSettled = true;
                    $this->cancelAll($promises);
                    $reject(new PromiseCancelledException(\sprintf('Promise at index "%s" was cancelled', $index)));

                    return;
                }
            }

            foreach ($promises as $index => $promise) {
                $promise->onCancel(function () use (&$isSettled, $reject, $promises, $index): void {
                    if ($isSettled) {
                        return;
                    }
                    $isSettled = true;
                    $this->cancelAll($promises);
                    $reject(new PromiseCancelledException(\sprintf('Promise at index "%s" was cancelled', $index)));
                });

                $promise
                    ->then(function ($value) use (&$results, &$completed, &$isSettled, $total, $index, $resolve): void {
                        if ($isSettled) {
                            return;
                        }

                        $results[$index] = $value;
                        if (++$completed === $total) {
                            $isSettled = true;
                            $resolve($results);
                        }
                    })
                    ->catch(function ($reason) use (&$isSettled, $reject, $promises, $index, $promise): void {
                        if ($isSettled) {
                            return;
                        }
                        $isSettled = true;
                        $this->cancelAll($promises);

                        if ($promise->isCancelled()) {
                            $reject(new PromiseCancelledException(\sprintf('Promise at index "%s" was cancelled', $index)));
                        } else {
                            $reject($reason);
                        }
                    })
                ;
            }
        });
    }

    /**
     * @template TAllSettledValue
     * @param  iterable<int|string, PromiseInterface<TAllSettledValue>>  $promises
     * @return PromiseInterface<array<int|string, SettledResult<TAllSettledValue, mixed>>>
     */
    public function allSettled(iterable $promises): PromiseInterface
    {
        $promises = \is_array($promises) ? $promises : \iterator_to_array($promises);

        /** @var Promise<array<int|string, SettledResult<TAllSettledValue, mixed>>> */
        return new Promise(function (callable $resolve) use ($promises): void {
            if ($promises === []) {
                $resolve([]);

                return;
            }

            $total = \count($promises);
            $results = \array_fill_keys(\array_keys($promises), null);
            $completed = 0;

            foreach ($promises as $index => $promise) {
                if (! ($promise instanceof PromiseInterface)) {
                    $results[$index] = SettledResult::rejected(new InvalidArgumentException('Not a PromiseInterface'));
                    if (++$completed === $total) {
                        $resolve($results);
                    }

                    continue;
                }

                if ($promise->isCancelled()) {
                    $results[$index] = SettledResult::cancelled();
                    if (++$completed === $total) {
                        $resolve($results);
                    }

                    continue;
                }

                $promise->onCancel(function () use (&$results, &$completed, $total, $index, $resolve): void {
                    $results[$index] = SettledResult::cancelled();
                    if (++$completed === $total) {
                        $resolve($results);
                    }
                });

                $promise
                    ->then(function ($value) use (&$results, &$completed, $total, $index, $resolve): void {
                        $results[$index] = SettledResult::fulfilled($value);
                        if (++$completed === $total) {
                            $resolve($results);
                        }
                    })
                    ->catch(function ($reason) use (&$results, &$completed, $total, $index, $resolve, $promise): void {
                        $results[$index] = $promise->isCancelled()
                            ? SettledResult::cancelled()
                            : SettledResult::rejected($reason);

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
     * @param  iterable<int|string, PromiseInterface<TRaceValue>>  $promises
     * @return PromiseInterface<TRaceValue>
     */
    /**
     * @template TRaceValue
     * @param  iterable<int|string, PromiseInterface<TRaceValue>>  $promises
     * @return PromiseInterface<TRaceValue>
     */
    public function race(iterable $promises): PromiseInterface
    {
        $promises = \is_array($promises) ? $promises : \iterator_to_array($promises);
        $settled = false;

        /** @var Promise<TRaceValue> $racePromise */
        $racePromise = new Promise(function (callable $resolve, callable $reject) use ($promises, &$settled): void {
            if ($promises === []) {
                $reject(new InvalidArgumentException('Cannot race with no promises provided'));

                return;
            }

            foreach ($promises as $index => $promise) {
                if (! ($promise instanceof PromiseInterface)) {
                    $settled = true;
                    $this->cancelAll($promises);
                    $reject(new InvalidArgumentException("Invalid item at $index"));

                    return;
                }
            }

            $cancellations = [];

            foreach ($promises as $index => $promise) {
                $promise->onCancel(function () use (&$cancellations, &$settled, $promises, $index, $reject): void {
                    if ($settled) {
                        return;
                    }

                    $cancellations[$index] = true;

                    if (\count($cancellations) === \count($promises)) {
                        $settled = true;
                        $this->cancelAll($promises);
                        $reject(new PromiseCancelledException('All promises in race were cancelled'));
                    }
                });

                $promise
                    ->then(
                        function ($value) use ($resolve, &$settled, $promises, $index): void {
                            if ($settled) {
                                return;
                            }

                            $this->handleWinnerSettlement($settled, $promises, $index);
                            $resolve($value);
                        }
                    )
                    ->catch(function ($reason) use ($reject, &$settled, $promises, $index, $promise): void {
                        if ($settled) {
                            return;
                        }

                        $this->handleWinnerSettlement($settled, $promises, $index);

                        $reject(
                            $promise->isCancelled()
                                ? new PromiseCancelledException("Race component at $index cancelled")
                                : $reason
                        );
                    })
                ;
            }
        });

        $racePromise->onCancel(function () use ($promises, &$settled): void {
            $settled = true;
            $this->cancelAll($promises);
        });

        return $racePromise;
    }

    /**
     * @template TAnyValue
     * @param  iterable<int|string, PromiseInterface<TAnyValue>>  $promises
     * @return PromiseInterface<TAnyValue>
     */
    public function any(iterable $promises): PromiseInterface
    {
        $promises = \is_array($promises) ? $promises : \iterator_to_array($promises);
        $settled = false;

        /** @var Promise<TAnyValue> $anyPromise */
        $anyPromise = new Promise(function (callable $resolve, callable $reject) use ($promises, &$settled): void {
            if (($total = \count($promises)) === 0) {
                $reject(new AggregateErrorException([], 'No promises provided'));

                return;
            }

            $rejections = [];
            foreach ($promises as $index => $promise) {
                if (! ($promise instanceof PromiseInterface)) {
                    $settled = true;
                    $this->cancelAll($promises);
                    $reject(new InvalidArgumentException("Invalid item at $index"));

                    return;
                }

                $promise->onCancel(function () use (&$rejections, &$settled, $total, $index, $reject): void {
                    if ($settled) {
                        return;
                    }

                    $rejections[$index] = new PromiseCancelledException('Cancelled');

                    if (\count($rejections) === $total) {
                        $settled = true;
                        $reject(new AggregateErrorException($rejections, 'All promises were rejected or cancelled'));
                    }
                });

                $promise
                    ->then(
                        function ($value) use ($resolve, &$settled, $promises, $index): void {
                            if ($settled) {
                                return;
                            }

                            $this->handleWinnerSettlement($settled, $promises, $index);
                            $resolve($value);
                        }
                    )
                    ->catch(function ($reason) use (&$rejections, &$settled, $total, $index, $reject, $promise): void {
                        if ($settled) {
                            return;
                        }

                        $rejections[$index] = $promise->isCancelled() ? new PromiseCancelledException('Cancelled') : $reason;

                        if (\count($rejections) === $total) {
                            $settled = true;
                            $reject(new AggregateErrorException($rejections, 'All promises were rejected or cancelled'));
                        }
                    })
                ;
            }
        });

        $anyPromise->onCancel(function () use ($promises, &$settled): void {
            $settled = true;
            $this->cancelAll($promises);
        });

        return $anyPromise;
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
     * @param  bool  $settled
     * @param  array<int|string, PromiseInterface<mixed>>  $promiseInstances
     * @param  int|string  $winnerIndex
     * @return void
     */
    private function handleWinnerSettlement(bool &$settled, array $promiseInstances, int|string $winnerIndex): void
    {
        $settled = true;
        foreach ($promiseInstances as $index => $promise) {
            if ($index !== $winnerIndex && $promise instanceof PromiseInterface) {
                $promise->cancelChain();
            }
        }
    }

    /**
     * @param  array<int|string, mixed>  $promises
     * @return void
     */
    private function cancelAll(array $promises): void
    {
        foreach ($promises as $promise) {
            if ($promise instanceof PromiseInterface) {
                $promise->cancelChain();
            }
        }
    }
}
