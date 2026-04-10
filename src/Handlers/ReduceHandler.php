<?php

declare(strict_types=1);

namespace Hibla\Promise\Handlers;

use Hibla\EventLoop\Loop;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

final readonly class ReduceHandler
{
    /**
     * @template TReduceItem
     * @template TReduceCarry
     *
     * @param iterable<int|string, TReduceItem> $items
     * @param callable(TReduceCarry, TReduceItem, int|string): (TReduceCarry|PromiseInterface<TReduceCarry>) $reducer
     * @param TReduceCarry $initial
     *
     * @return PromiseInterface<TReduceCarry>
     */
    public function reduce(iterable $items, callable $reducer, mixed $initial = null): PromiseInterface
    {
        /** @var PromiseInterface<TReduceCarry>|null $currentStepPromise */
        $currentStepPromise = null;

        $state = (object) ['cancelled' => false];

        /** @var Promise<TReduceCarry> $reducePromise */
        $reducePromise = new Promise(function (callable $resolve, callable $reject) use ($items, $reducer, $initial, &$currentStepPromise, $state): void {
            try {
                $iterator = $this->getIterator($items);
                $iterator->rewind();

                if (! $iterator->valid()) {
                    $resolve($initial);

                    return;
                }
            } catch (\Throwable $e) {
                $reject($e);

                return;
            }

            $step = function (mixed $carry) use (&$step, $iterator, $reducer, $resolve, $reject, &$currentStepPromise, $state): void {
                if ($state->cancelled) {
                    return;
                }

                if (! $iterator->valid()) {
                    $resolve($carry);

                    return;
                }

                $key = $iterator->key();
                $item = $iterator->current();
                $iterator->next();

                try {
                    $inputPromise = $item instanceof PromiseInterface
                        ? $item
                        : Promise::resolved($item);

                    $currentStepPromise = $inputPromise
                        ->then(fn ($resolvedValue) => $reducer($carry, $resolvedValue, $key))
                        ->then(
                            function (mixed $nextCarry) use (&$step, &$currentStepPromise): void {
                                $currentStepPromise = null;
                                Loop::microTask(fn () => $step($nextCarry));
                            },
                            $reject
                        )
                    ;
                } catch (\Throwable $e) {
                    $reject($e);
                }
            };

            Loop::microTask(fn () => $step($initial));
        });

        $reducePromise->onCancel(function () use (&$currentStepPromise, $state): void {
            $state->cancelled = true;
            $currentStepPromise?->cancelChain();
        });

        return $reducePromise;
    }

    /**
     * Normalizes an iterable into a manual Iterator without materializing it.
     *
     * @param iterable<int|string, mixed> $items
     * @return \Iterator<int|string, mixed>
     */
    private function getIterator(iterable $items): \Iterator
    {
        if ($items instanceof \Iterator) {
            return $items;
        }

        if ($items instanceof \IteratorAggregate) {
            return $items->getIterator();
        }

        return (fn () => yield from $items)();
    }
}
