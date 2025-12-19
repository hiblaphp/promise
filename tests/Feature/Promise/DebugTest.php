<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\PromiseCancelledException;
use Hibla\Promise\Promise;

describe('Isolated Batch Cancellation Debug', function () {
    it('shows the timing issue with batch cancellation', function () {
        $executionLog = [];

        $tasks = [
            'task1' => function () use (&$executionLog) {
                return new Promise(function ($resolve) use (&$executionLog) {
                    $executionLog[] = 'task1_started';
                    Loop::microTask(fn () => $resolve('result1'));
                });
            },
            'task2' => function () use (&$executionLog) {
                return new Promise(function ($resolve) use (&$executionLog) {
                    $executionLog[] = 'task2_started';
                    Loop::microTask(fn () => $resolve('result2'));
                });
            },
            'task3' => function () use (&$executionLog) {
                return new Promise(function ($resolve, $reject) use (&$executionLog) {
                    $executionLog[] = 'task3_created';
                    Loop::microTask(function () use ($resolve, &$executionLog) {
                        $executionLog[] = 'task3_resolved';
                        $resolve('result3');
                    });
                });
            },
            'task4' => fn () => Promise::resolved('result4'),
        ];

        // Schedule cancellation on nextTick
        Loop::nextTick(function () use (&$executionLog, $tasks) {
            $executionLog[] = 'cancel_triggered';
            $tasks['task3']()->cancel();
            $executionLog[] = 'cancel_called';
        });

        $exception = null;

        try {
            $result = Promise::batch($tasks, batchSize: 2)->wait();
            $executionLog[] = 'batch_resolved';
        } catch (PromiseCancelledException $e) {
            $executionLog[] = 'batch_rejected_cancelled';
            $exception = $e;
        } catch (Exception $e) {
            $executionLog[] = 'batch_rejected_error: ' . $e->getMessage();
            $exception = $e;
        }

        // Debug output
        echo "\n\nExecution Log:\n";
        foreach ($executionLog as $index => $log) {
            echo "[$index] $log\n";
        }

        expect($exception)->toBeInstanceOf(PromiseCancelledException::class)
            ->and($executionLog)->toContain('task1_started', 'task2_started')
        ;
    });

    it('shows promise instance from task callable', function () {
        $tasks = [
            'task1' => fn () => new Promise(fn ($resolve) => Loop::microTask(fn () => $resolve('r1'))),
            'task2' => fn () => new Promise(fn ($resolve) => Loop::microTask(fn () => $resolve('r2'))),
            'task3' => function () {
                return new Promise(function ($resolve) {
                    Loop::microTask(fn () => $resolve('r3'));
                });
            },
        ];

        $log = [];

        // Get task3 promise immediately
        $task3Promise = $tasks['task3']();
        $log[] = 'task3_promise_created';

        Loop::nextTick(function () use ($task3Promise, &$log) {
            $log[] = 'about_to_cancel';
            $task3Promise->cancel();
            $log[] = 'cancel_called';
        });

        $exception = null;

        try {
            Promise::batch($tasks, batchSize: 2)->wait();
            $log[] = 'batch_completed';
        } catch (PromiseCancelledException $e) {
            $log[] = 'caught_cancellation';
            $exception = $e;
        }

        echo "\n\nPromise Instance Log:\n";
        foreach ($log as $index => $msg) {
            echo "[$index] $msg\n";
        }

        expect($exception)->toBeInstanceOf(PromiseCancelledException::class);
    });

    it('isolates concurrent execution directly', function () {
        $log = [];

        $tasks = [
            'task1' => function () use (&$log) {
                return new Promise(function ($resolve) use (&$log) {
                    $log[] = 't1_exec';
                    Loop::microTask(fn () => $resolve('r1'));
                });
            },
            'task2' => function () use (&$log) {
                return new Promise(function ($resolve) use (&$log) {
                    $log[] = 't2_exec';
                    Loop::microTask(fn () => $resolve('r2'));
                });
            },
            'task3' => function () use (&$log) {
                return new Promise(function ($resolve) use (&$log) {
                    $log[] = 't3_exec';
                    Loop::microTask(fn () => $resolve('r3'));
                });
            },
        ];

        $exception = null;

        // Schedule cancellation AFTER concurrent starts
        Loop::nextTick(function () use ($tasks, &$log) {
            $log[] = 'cancel_scheduled';
            // This creates a NEW promise instance - not stored in handler!
            $tasks['task3']()->cancel();
            $log[] = 'cancel_executed';
        });

        try {
            $result = Promise::concurrent($tasks, concurrency: 2)->wait();
            $log[] = 'concurrent_completed';
        } catch (PromiseCancelledException $e) {
            $log[] = 'caught_cancelled';
            $exception = $e;
        }

        echo "\n\nConcurrent Log:\n";
        foreach ($log as $index => $msg) {
            echo "[$index] $msg\n";
        }

        expect($exception)->toBeInstanceOf(PromiseCancelledException::class);
    });
});
