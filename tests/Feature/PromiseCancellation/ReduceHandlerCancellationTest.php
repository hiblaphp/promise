<?php

use Hibla\EventLoop\Loop;
use Hibla\Promise\Promise;

it('cancels the in-flight step when reduce promise is cancelled', function () {
    $cancelledCount = 0;
    $completedCount = 0;

    $items = range(1, 5);

    $reduce = Promise::reduce(
        $items,
        function (int $carry, int $item) use (&$cancelledCount, &$completedCount) {
            $promise = new Promise();

            $timerId = Loop::addTimer(0.5, function () use ($promise, $carry, $item, &$completedCount) {
                $completedCount++;
                $promise->resolve($carry + $item);
            });

            $promise->onCancel(function () use ($timerId, &$cancelledCount) {
                Loop::cancelTimer($timerId);
                $cancelledCount++;
            });

            return $promise;
        },
        initial: 0
    );

    Loop::addTimer(0.1, fn () => $reduce->cancel());
    Loop::run();

    expect($cancelledCount)->toBe(1)
        ->and($completedCount)->toBe(0)
        ->and($reduce->isCancelled())->toBeTrue();
});

it('does not advance to the next step after cancellation', function () {
    $stepsStarted = 0;

    $items = range(1, 5);

    $reduce = Promise::reduce(
        $items,
        function (int $carry, int $item) use (&$stepsStarted) {
            $stepsStarted++;

            $promise = new Promise();

            $timerId = Loop::addTimer(0.5, fn () => $promise->resolve($carry + $item));

            $promise->onCancel(fn () => Loop::cancelTimer($timerId));

            return $promise;
        },
        initial: 0
    );

    Loop::addTimer(0.1, fn () => $reduce->cancel());
    Loop::run();

    expect($stepsStarted)->toBe(1)
        ->and($reduce->isCancelled())->toBeTrue();
});

it('cancels between steps cleanly when no step is in-flight', function () {
    $secondStepStarted = false;
    $reduce = null;

    $items = range(1, 3);

    $reduce = Promise::reduce(
        $items,
        function (int $carry, int $item) use (&$secondStepStarted, &$reduce) {
            if ($item === 1) {
                return Promise::resolved($carry + $item)->then(function ($v) use (&$reduce) {
                    $reduce?->cancel();

                    return $v;
                });
            }
            
            $secondStepStarted = true;

            return Promise::resolved($carry + $item);
        },
        initial: 0
    );

    Loop::run();

    expect($secondStepStarted)->toBeFalse()
        ->and($reduce->isCancelled())->toBeTrue();
});