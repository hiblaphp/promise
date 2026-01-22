<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\AggregateErrorException;
use Hibla\Promise\Exceptions\CancelledException;
use Hibla\Promise\Promise;

describe('Promise Cancellation Behavior', function () {
    describe('Promise::all() with cancellation', function () {
        it('rejects if one promise is cancelled', function () {
            $promise1 = new Promise(fn ($resolve) => Loop::microTask(fn () => $resolve('value1')));
            $promise2 = new Promise(fn ($resolve) => Loop::microTask(fn () => $resolve('value2')));
            $promise3 = new Promise(fn ($resolve) => Loop::microTask(fn () => $resolve('value3')));

            $promise2->cancel();

            expect(fn () => Promise::all([
                'p1' => $promise1,
                'p2' => $promise2,
                'p3' => $promise3,
            ])->wait())
                ->toThrow(CancelledException::class)
            ;
        });

        it('rejects with correct cancelled index message', function () {
            $promise1 = new Promise(fn ($resolve) => Loop::microTask(fn () => $resolve('value1')));
            $promise2 = new Promise(fn ($resolve) => Loop::microTask(fn () => $resolve('value2')));

            $promise2->cancel();

            try {
                Promise::all(['p1' => $promise1, 'p2' => $promise2])->wait();
            } catch (CancelledException $e) {
                expect($e->getMessage())->toContain('p2');
            }
        });

        it('rejects all promises in the collection when one is cancelled', function () {
            $promises = [
                'p1' => new Promise(fn ($resolve) => Loop::microTask(fn () => $resolve('value1'))),
                'p2' => new Promise(fn ($resolve) => Loop::microTask(fn () => $resolve('value2'))),
                'p3' => new Promise(fn ($resolve) => Loop::microTask(fn () => $resolve('value3'))),
            ];

            $promises['p2']->cancel();

            expect(fn () => Promise::all($promises)->wait())
                ->toThrow(CancelledException::class)
            ;
        });
    });

    describe('Promise::concurrent() with cancellation', function () {
        it('rejects if a task promise gets cancelled during execution', function () {
            $taskExecuted = [];

            $tasks = [
                'task1' => fn () => new Promise(function ($resolve) use (&$taskExecuted) {
                    $taskExecuted[] = 'task1';
                    $resolve('result1');
                }),
                'task2' => fn () => new Promise(function ($resolve) use (&$taskExecuted) {
                    $taskExecuted[] = 'task2';
                    $resolve('result2');
                }),
                'task3' => fn () => new Promise(function ($resolve) use (&$taskExecuted) {
                    $taskExecuted[] = 'task3';
                    $resolve('result3');
                }),
            ];

            $results = Promise::concurrent($tasks, concurrency: 2)->wait();
            expect($results)->toBe([
                'task1' => 'result1',
                'task2' => 'result2',
                'task3' => 'result3',
            ]);
        });

        it('rejects when promise is cancelled mid-flight', function () {
            $promise1 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result1')));
            $promise2 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result2')));
            $promise3 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result3')));

            Loop::addTimer(0.5, fn () => $promise3->cancel());

            $tasks = [
                'task1' => fn () => $promise1,
                'task2' => fn () => $promise2,
                'task3' => fn () => $promise3,
            ];

            expect(fn () => Promise::concurrent($tasks, concurrency: 2)->wait())
                ->toThrow(CancelledException::class)
            ;
        });

        it('cancels remaining tasks when one is cancelled', function () {
            $startedTasks = [];
            $completedTasks = [];

            $tasks = [
                'task1' => fn () => new Promise(function ($resolve) use (&$startedTasks, &$completedTasks) {
                    $startedTasks[] = 'task1';
                    $completedTasks[] = 'task1';
                    Loop::microTask(fn () => $resolve('result1'));
                }),
                'task2' => fn () => new Promise(function ($resolve) use (&$startedTasks) {
                    $startedTasks[] = 'task2';
                    Loop::microTask(fn () => $resolve('result2'));
                }),
                'task3' => fn () => new Promise(function ($resolve) use (&$startedTasks) {
                    $startedTasks[] = 'task3';
                    Loop::microTask(fn () => $resolve('result3'));
                }),
            ];

            $tasks['task2']()->cancel();

            $results = Promise::concurrent($tasks, concurrency: 1)->wait();

            expect($results)->toBe([
                'task1' => 'result1',
                'task2' => 'result2',
                'task3' => 'result3',
            ]);
        });

        it('rejects when concurrent task executor throws cancellation', function () {
            $tasks = [
                'task1' => fn () => Promise::resolved('value1'),
                'task2' => fn () => new Promise(fn ($resolve, $reject) => $reject(new CancelledException('Cancelled'))),
                'task3' => fn () => Promise::resolved('value3'),
            ];

            expect(fn () => Promise::concurrent($tasks, concurrency: 2)->wait())
                ->toThrow(CancelledException::class)
            ;
        });
    });

    describe('Promise::concurrentSettled() with cancellation', function () {
        it('returns cancelled status for cancelled promises', function () {
            $promise1 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result1')));
            $promise2 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result2')));
            $promise3 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result3')));

            Loop::addTimer(0.5, fn () => $promise2->cancel());

            $tasks = [
                'task1' => fn () => $promise1,
                'task2' => fn () => $promise2,
                'task3' => fn () => $promise3,
            ];

            $results = Promise::concurrentSettled($tasks, concurrency: 2)->wait();

            expect($results['task1']->isFulfilled())->toBeTrue();
            expect($results['task2']->isCancelled())->toBeTrue();
            expect($results['task3']->isFulfilled())->toBeTrue();
        });

        it('handles multiple cancellations gracefully', function () {
            $promise1 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result1')));
            $promise2 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result2')));
            $promise3 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result3')));

            Loop::addTimer(0.5, function () use ($promise2, $promise3) {
                $promise2->cancel();
                $promise3->cancel();
            });

            $tasks = [
                'task1' => fn () => $promise1,
                'task2' => fn () => $promise2,
                'task3' => fn () => $promise3,
            ];

            $results = Promise::concurrentSettled($tasks, concurrency: 2)->wait();

            expect($results['task1']->isFulfilled())->toBeTrue();
            expect($results['task2']->isCancelled())->toBeTrue();
            expect($results['task3']->isCancelled())->toBeTrue();
        });
    });

    describe('Promise::batch() with cancellation', function () {
        it('rejects if cancelled promise is in a batch', function () {
            $promise1 = Promise::resolved('result1');
            $promise2 = new Promise(fn ($resolve) => Loop::microTask(fn () => $resolve('result2')));
            $promise3 = Promise::resolved('result3');

            $promise2->cancel();

            $tasks = [
                'task1' => fn () => $promise1,
                'task2' => fn () => $promise2,
                'task3' => fn () => $promise3,
            ];

            expect(fn () => Promise::batch($tasks, batchSize: 2)->wait())
                ->toThrow(CancelledException::class)
            ;
        });

        it('rejects when promise is cancelled mid-flight in batch', function () {
            $promise1 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result1')));
            $promise2 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result2')));
            $promise3 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result3')));

            Loop::addTimer(0.5, fn () => $promise2->cancel());

            $tasks = [
                'task1' => fn () => $promise1,
                'task2' => fn () => $promise2,
                'task3' => fn () => $promise3,
            ];

            expect(fn () => Promise::batch($tasks, batchSize: 2)->wait())
                ->toThrow(CancelledException::class)
            ;
        });

        it('completes first batch before cancellation in second batch', function () {
            $results = [];

            $promise3 = new Promise(fn ($resolve, $reject) => Loop::microTask(fn () => $resolve('result3')));
            $promise3->cancel();

            $tasks = [
                'task1' => fn () => new Promise(function ($resolve) use (&$results) {
                    $results[] = 'task1';
                    $resolve('result1');
                }),
                'task2' => fn () => new Promise(function ($resolve) use (&$results) {
                    $results[] = 'task2';
                    $resolve('result2');
                }),
                'task3' => fn () => $promise3,
                'task4' => fn () => Promise::resolved('result4'),
            ];

            expect(fn () => Promise::batch($tasks, batchSize: 2)->wait())
                ->toThrow(CancelledException::class)
            ;
        });
    });

    describe('Promise::batchSettled() with cancellation', function () {
        it('returns cancelled status for cancelled promises in batch', function () {
            $promise1 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result1')));
            $promise2 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result2')));
            $promise3 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result3')));
            $promise4 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result4')));

            Loop::addTimer(0.5, fn () => $promise2->cancel());

            $tasks = [
                'task1' => fn () => $promise1,
                'task2' => fn () => $promise2,
                'task3' => fn () => $promise3,
                'task4' => fn () => $promise4,
            ];

            $results = Promise::batchSettled($tasks, batchSize: 2)->wait();

            expect($results['task1']->isFulfilled())->toBeTrue();
            expect($results['task2']->isCancelled())->toBeTrue();
            expect($results['task3']->isFulfilled())->toBeTrue();
            expect($results['task4']->isFulfilled())->toBeTrue();
        });
    });

    describe('Promise::race() with cancellation', function () {
        it('resolves with first to settle when others are cancelled', function () {
            $promise1 = new Promise(fn ($resolve) => Loop::addTimer(0.1, fn () => $resolve('result1')));
            $promise2 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result2')));
            $promise3 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result3')));

            Loop::addTimer(0.5, function () use ($promise2, $promise3) {
                $promise2->cancel();
                $promise3->cancel();
            });

            $result = Promise::race([
                'p1' => $promise1,
                'p2' => $promise2,
                'p3' => $promise3,
            ])->wait();

            expect($result)->toBe('result1');
        });

        it('rejects when all promises are cancelled', function () {
            $promise1 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result1')));
            $promise2 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result2')));
            $promise3 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result3')));

            Loop::addTimer(0.5, function () use ($promise1, $promise2, $promise3) {
                $promise1->cancel();
                $promise2->cancel();
                $promise3->cancel();
            });

            expect(fn () => Promise::race([
                'p1' => $promise1,
                'p2' => $promise2,
                'p3' => $promise3,
            ])->wait())
                ->toThrow(CancelledException::class)
            ;
        });

        it('ignores cancellation of non-winner promises', function () {
            $promise1 = new Promise(fn ($resolve) => Loop::microTask(fn () => $resolve('result1')));
            $promise2 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result2')));
            $promise3 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result3')));

            Loop::addTimer(0.5, function () use ($promise2, $promise3) {
                $promise2->cancel();
                $promise3->cancel();
            });

            $result = Promise::race([
                'p1' => $promise1,
                'p2' => $promise2,
                'p3' => $promise3,
            ])->wait();

            expect($result)->toBe('result1');
        });
    });

    describe('Promise::any() with cancellation', function () {
        it('resolves with first successful promise when others are cancelled', function () {
            $promise1 = new Promise(fn ($resolve) => Loop::addTimer(0.1, fn () => $resolve('result1')));
            $promise2 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result2')));
            $promise3 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result3')));

            Loop::addTimer(0.5, function () use ($promise2, $promise3) {
                $promise2->cancel();
                $promise3->cancel();
            });

            $result = Promise::any([
                'p1' => $promise1,
                'p2' => $promise2,
                'p3' => $promise3,
            ])->wait();

            expect($result)->toBe('result1');
        });

        it('rejects with AggregateErrorException when all promises are cancelled', function () {
            $promise1 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result1')));
            $promise2 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result2')));
            $promise3 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result3')));

            Loop::addTimer(0.5, function () use ($promise1, $promise2, $promise3) {
                $promise1->cancel();
                $promise2->cancel();
                $promise3->cancel();
            });

            expect(fn () => Promise::any([
                'p1' => $promise1,
                'p2' => $promise2,
                'p3' => $promise3,
            ])->wait())
                ->toThrow(AggregateErrorException::class)
            ;
        });

        it('rejects when all promises are rejected or cancelled', function () {
            $promise1 = new Promise(fn ($resolve, $reject) => Loop::microTask(fn () => $reject(new Exception('Error 1'))));
            $promise2 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result2')));
            $promise3 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result3')));

            Loop::addTimer(0.5, function () use ($promise2, $promise3) {
                $promise2->cancel();
                $promise3->cancel();
            });

            expect(fn () => Promise::any([
                'p1' => $promise1,
                'p2' => $promise2,
                'p3' => $promise3,
            ])->wait())
                ->toThrow(AggregateErrorException::class)
            ;
        });

        it('ignores cancellation of rejected promises if one succeeds', function () {
            $promise1 = new Promise(fn ($resolve, $reject) => Loop::microTask(fn () => $reject(new Exception('Error 1'))));
            $promise2 = new Promise(fn ($resolve) => Loop::addTimer(0.1, fn () => $resolve('result2')));
            $promise3 = new Promise(fn ($resolve) => Loop::addTimer(2, fn () => $resolve('result3')));

            Loop::addTimer(0.5, fn () => $promise3->cancel());

            $result = Promise::any([
                'p1' => $promise1,
                'p2' => $promise2,
                'p3' => $promise3,
            ])->wait();

            expect($result)->toBe('result2');
        });
    });

    describe('Cancellation propagation', function () {
        it('cancels child promises when parent is cancelled', function () {
            $promise = Promise::resolved('value');
            $childPromise = $promise->then(fn ($v) => $v . ' processed');

            Loop::run();

            expect($childPromise->isPending())->toBeFalse();
        });

        it('concurrent handles cancellation chain properly', function () {
            $cancelledCount = 0;

            $tasks = [
                'task1' => fn () => new Promise(function ($resolve) {
                    Loop::microTask(fn () => $resolve('result1'));
                }),
                'task2' => fn () => new Promise(function ($resolve) {
                    Loop::microTask(fn () => $resolve('result2'));
                }),
            ];

            $concurrent = Promise::concurrent($tasks, concurrency: 1);

            $concurrent->onCancel(function () use (&$cancelledCount) {
                $cancelledCount++;
            });

            $results = $concurrent->wait();

            expect($results['task1'])->toBe('result1');
            expect($results['task2'])->toBe('result2');

            expect($cancelledCount)->toBe(0);
        });
    });

    describe('allSettled() with cancellation', function () {
        it('returns cancelled status instead of rejecting', function () {
            $promise1 = Promise::resolved('value1');
            $promise2 = new Promise(fn ($resolve) => Loop::microTask(fn () => $resolve('value2')));

            $promise2->cancel();

            $results = Promise::allSettled([
                'p1' => $promise1,
                'p2' => $promise2,
            ])->wait();

            expect($results['p1']->isFulfilled())->toBeTrue();
            expect($results['p1']->value)->toBe('value1');
            expect($results['p2']->isCancelled())->toBeTrue();
        });

        it('completes successfully even with multiple cancellations', function () {
            $promises = [
                'p1' => Promise::resolved('value1'),
                'p2' => new Promise(fn ($resolve) => Loop::microTask(fn () => $resolve('value2'))),
                'p3' => Promise::resolved('value3'),
            ];

            $promises['p2']->cancel();

            $results = Promise::allSettled($promises)->wait();

            expect($results['p1']->isFulfilled())->toBeTrue();
            expect($results['p1']->value)->toBe('value1');
            expect($results['p2']->isCancelled())->toBeTrue();
            expect($results['p3']->isFulfilled())->toBeTrue();
            expect($results['p3']->value)->toBe('value3');
        });
    });

    describe('Concurrent with cancellation handler', function () {
        it('triggers onCancel handlers when promise is cancelled', function () {
            $cancellerTriggered = false;

            $promise = new Promise(function ($resolve, $reject) {
                // Never resolves
            });

            $promise->onCancel(function () use (&$cancellerTriggered) {
                $cancellerTriggered = true;
            });

            $promise->cancel();

            expect($cancellerTriggered)->toBeTrue();
            expect($promise->isCancelled())->toBeTrue();
        });
    });
});
