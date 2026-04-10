<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Promise\Handlers\ConcurrencyHandler;
use Hibla\Promise\Promise;

it('cancels in-flight tasks when batch promise is cancelled', function () {
    $handler = new ConcurrencyHandler();

    $cancelledCount = 0;
    $completedCount = 0;

    $tasks = array_map(function (int $i) use (&$cancelledCount, &$completedCount) {
        return function () use ($i, &$cancelledCount, &$completedCount) {
            $promise = new Promise();

            $timerId = Loop::addTimer(0.5, function () use ($promise, &$completedCount) {
                $completedCount++;
                $promise->resolve('completed');
            });

            $promise->onCancel(function () use ($timerId, &$cancelledCount) {
                Loop::cancelTimer($timerId);
                $cancelledCount++;
            });

            return $promise;
        };
    }, range(0, 9));

    $batch = $handler->batch($tasks, batchSize: 10, concurrency: 5);

    Loop::addTimer(0.1, fn () => $batch->cancel());
    Loop::run();

    expect($cancelledCount)->toBe(5)
        ->and($completedCount)->toBe(0)
        ->and($batch->isCancelled())->toBeTrue()
    ;
});

it('does not advance to the next batch after cancellation', function () {
    $handler = new ConcurrencyHandler();

    $startedCount = 0;

    $tasks = array_map(function (int $i) use (&$startedCount) {
        return function () use ($i, &$startedCount) {
            $startedCount++;
            $promise = new Promise();

            $timerId = Loop::addTimer(0.5, fn () => $promise->resolve('completed'));

            $promise->onCancel(fn () => Loop::cancelTimer($timerId));

            return $promise;
        };
    }, range(0, 19));

    $batch = $handler->batch($tasks, batchSize: 10, concurrency: 5);

    Loop::addTimer(0.1, fn () => $batch->cancel());
    Loop::run();

    expect($startedCount)->toBe(5)
        ->and($batch->isCancelled())->toBeTrue()
    ;
});

it('cancels in-flight tasks when batchSettled promise is cancelled', function () {
    $handler = new ConcurrencyHandler();

    $cancelledCount = 0;
    $completedCount = 0;

    $tasks = array_map(function (int $i) use (&$cancelledCount, &$completedCount) {
        return function () use ($i, &$cancelledCount, &$completedCount) {
            $promise = new Promise();

            $timerId = Loop::addTimer(0.5, function () use ($promise, &$completedCount) {
                $completedCount++;
                $promise->resolve('completed');
            });

            $promise->onCancel(function () use ($timerId, &$cancelledCount) {
                Loop::cancelTimer($timerId);
                $cancelledCount++;
            });

            return $promise;
        };
    }, range(0, 9));

    $batch = $handler->batchSettled($tasks, batchSize: 10, concurrency: 5);

    Loop::addTimer(0.1, fn () => $batch->cancel());
    Loop::run();

    expect($cancelledCount)->toBe(5)
        ->and($completedCount)->toBe(0)
        ->and($batch->isCancelled())->toBeTrue()
    ;
});

it('cancels between batches cleanly when no tasks are in-flight', function () {
    $handler = new ConcurrencyHandler();

    $secondBatchStarted = false;
    $batch = null;

    $tasks = array_map(function (int $i) use (&$secondBatchStarted, &$batch) {
        return function () use ($i, &$secondBatchStarted, &$batch) {
            if ($i < 2) {
                return Promise::resolved('first-batch')->then(function ($v) use (&$batch) {
                    $batch?->cancel();

                    return $v;
                });
            }

            $secondBatchStarted = true;

            return Promise::resolved('second-batch');
        };
    }, range(0, 3));

    $batch = $handler->batch($tasks, batchSize: 2, concurrency: 2);
    Loop::run();

    expect($secondBatchStarted)->toBeFalse()
        ->and($batch->isCancelled())->toBeTrue();
});
