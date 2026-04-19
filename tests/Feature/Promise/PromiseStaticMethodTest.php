<?php

declare(strict_types=1);

use function Hibla\delay;

use Hibla\Promise\Promise;
use Hibla\Promise\SettledResult;

describe('Promise Static Methods', function () {

    describe('Promise::all', function () {
        it('resolves when all promises resolve', function () {
            $promise1 = Promise::resolved('value1');
            $promise2 = Promise::resolved('value2');
            $promise3 = Promise::resolved('value3');

            $result = Promise::all([$promise1, $promise2, $promise3])->wait();

            expect($result)->toBe(['value1', 'value2', 'value3']);
        });

        it('rejects when any promise rejects', function () {
            $exception = new Exception('error');

            try {
                $promise1 = Promise::resolved('value1');
                $promise2 = Promise::rejected($exception);
                $promise3 = Promise::resolved('value3');

                Promise::all([$promise1, $promise2, $promise3])->wait();
                expect(false)->toBeTrue('Expected exception to be thrown');
            } catch (Exception $e) {
                expect($e)->toBe($exception);
            }
        });

        it('handles empty array', function () {
            $result = Promise::all([])->wait();

            expect($result)->toBe([]);
        });

        it('preserves order of results', function () {
            $promises = [
                Promise::resolved('first'),
                Promise::resolved('second'),
                Promise::resolved('third'),
            ];

            $result = Promise::all($promises)->wait();

            expect($result[0])->toBe('first')
                ->and($result[1])->toBe('second')
                ->and($result[2])->toBe('third')
            ;
        });
    });

    describe('Promise::allSettled', function () {
        it('resolves with all results regardless of outcome', function () {
            $promise1 = Promise::resolved('value1');
            $promise2 = Promise::rejected(new Exception('error'));
            $promise3 = Promise::resolved('value3');

            $result = Promise::allSettled([$promise1, $promise2, $promise3])->wait();

            expect($result)->toHaveCount(3);

            expect($result[0]->isFulfilled())->toBeTrue();
            expect($result[0]->value)->toBe('value1');
            expect($result[1]->isRejected())->toBeTrue();
            expect($result[1]->reason)->toBeInstanceOf(Exception::class);
            expect($result[2]->isFulfilled())->toBeTrue();
            expect($result[2]->value)->toBe('value3');
        });

        it('handles empty array', function () {
            $result = Promise::allSettled([])->wait();

            expect($result)->toBe([]);
        });

        it('never rejects even when all tasks fail', function () {
            $promise1 = Promise::rejected(new Exception('error1'));
            $promise2 = Promise::rejected(new Exception('error2'));
            $promise3 = Promise::rejected(new Exception('error3'));

            $result = Promise::allSettled([$promise1, $promise2, $promise3])->wait();

            expect($result)->toHaveCount(3);

            expect($result[0]->isRejected())->toBeTrue();
            expect($result[1]->isRejected())->toBeTrue();
            expect($result[2]->isRejected())->toBeTrue();
        });
    });

    describe('Promise::any', function () {
        it('resolves with the first resolved promise even when earlier promises reject', function () {
            $promise1 = Promise::rejected(new Exception('first error'));
            $promise2 = Promise::rejected(new Exception('second error'));
            $promise3 = Promise::resolved('third success');
            $promise4 = Promise::resolved('fourth success');

            $result = Promise::any([$promise1, $promise2, $promise3, $promise4])->wait();

            expect($result)->toBe('third success');
        });

        it('rejects with AggregateException when all promises reject', function () {
            try {
                $promise1 = Promise::rejected(new Exception('first error'));
                $promise2 = Promise::rejected(new Exception('second error'));
                $promise3 = Promise::rejected(new Exception('third error'));

                Promise::any([$promise1, $promise2, $promise3])->wait();
                expect(false)->toBeTrue('Expected AggregateException to be thrown');
            } catch (Exception $e) {
                expect($e)->toBeInstanceOf(Exception::class);
            }
        });

        it('resolves immediately with the first successful promise in mixed order', function () {
            $promise1 = new Promise();
            $promise2 = Promise::resolved('quick success');
            $promise3 = new Promise();

            $anyPromise = Promise::any([$promise1, $promise2, $promise3]);

            $promise1->reject(new Exception('delayed error'));
            $promise3->resolve('delayed success');

            $result = $anyPromise->wait();

            expect($result)->toBe('quick success');
        });

        it('handles empty array by rejecting', function () {
            try {
                Promise::any([])->wait();
                expect(false)->toBeTrue('Expected exception to be thrown for empty array');
            } catch (Exception $e) {
                expect($e)->toBeInstanceOf(Exception::class);
            }
        });

        it('resolves with first successful promise when mixed with pending promises', function () {
            $promise1 = Promise::rejected(new Exception('error'));
            $promise2 = new Promise();
            $promise3 = Promise::resolved('success');
            $promise4 = new Promise();

            $result = Promise::any([$promise1, $promise2, $promise3, $promise4])->wait();

            expect($result)->toBe('success');
        });
    });

    describe('Promise::race', function () {
        it('resolves with the first settled promise value', function () {
            $promise1 = Promise::resolved('fast');
            $promise2 = new Promise();
            $promise3 = new Promise();

            $result = Promise::race([$promise1, $promise2, $promise3])->wait();

            expect($result)->toBe('fast');
        });

        it('rejects with the first settled promise reason', function () {
            $exception = new Exception('fast error');

            try {
                $promise1 = Promise::rejected($exception);
                $promise2 = new Promise(); // never settles

                Promise::race([$promise1, $promise2])->wait();
                expect(false)->toBeTrue('Expected exception to be thrown');
            } catch (Exception $e) {
                expect($e)->toBe($exception);
            }
        });
    });

    describe('Promise::concurrent', function () {
        it('executes tasks with concurrency limit', function () {
            $executionOrder = [];
            $startTime = microtime(true);

            $tasks = [];
            for ($i = 0; $i < 10; $i++) {
                $tasks[] = function () use ($i, &$executionOrder) {
                    return delay(0.1)->then(function () use ($i, &$executionOrder) {
                        $executionOrder[] = $i;

                        return "task-{$i}";
                    });
                };
            }

            $result = Promise::concurrent($tasks, 3)->wait();
            $executionTime = microtime(true) - $startTime;

            expect($result)->toHaveCount(10);
            expect($executionTime)->toBeGreaterThan(0.3);
            expect($executionTime)->toBeLessThan(0.6);
        });

        it('respects concurrency parameter', function () {
            $startTime = microtime(true);

            $tasks = [];
            for ($i = 0; $i < 6; $i++) {
                $tasks[] = fn () => delay(0.1)->then(fn () => "task-{$i}");
            }

            $result = Promise::concurrent($tasks, 2)->wait();
            $executionTime = microtime(true) - $startTime;

            expect($result)->toHaveCount(6);
            expect($executionTime)->toBeGreaterThan(0.25);
            expect($executionTime)->toBeLessThan(0.4);
        });

        it('handles empty task array', function () {
            $result = Promise::concurrent([], 5)->wait();

            expect($result)->toBe([]);
        });

        it('handles task failures', function () {
            try {
                $tasks = [
                    fn () => delay(0.05)->then(fn () => 'task-0'),
                    fn () => delay(0.05)->then(fn () => throw new Exception('concurrent error')),
                    fn () => delay(0.05)->then(fn () => 'task-2'),
                ];

                Promise::concurrent($tasks, 2)->wait();
                expect(false)->toBeTrue('Expected exception to be thrown');
            } catch (Exception $e) {
                expect($e->getMessage())->toBe('concurrent error');
            }
        });

        it('maintains result order', function () {
            $tasks = [];
            for ($i = 0; $i < 5; $i++) {
                $tasks[] = function () use ($i) {
                    return delay(0.05)->then(fn () => "task-{$i}");
                };
            }

            $result = Promise::concurrent($tasks, 2)->wait();

            expect($result)->toHaveCount(5);
            for ($i = 0; $i < 5; $i++) {
                expect($result[$i])->toBe("task-{$i}");
            }
        });
    });

    describe('Promise::concurrentSettled', function () {
        it('executes tasks with concurrency limit and returns all settlement results', function () {
            $tasks = [
                fn () => delay(0.05)->then(fn () => 'success1'),
                fn () => delay(0.05)->then(fn () => throw new Exception('error1')),
                fn () => delay(0.05)->then(fn () => 'success2'),
                fn () => delay(0.05)->then(fn () => throw new Exception('error2')),
            ];

            $result = Promise::concurrentSettled($tasks, 2)->wait();

            expect($result)->toHaveCount(4);

            expect($result[0])->toBeInstanceOf(SettledResult::class);
            expect($result[0]->isFulfilled())->toBeTrue();
            expect($result[0]->value)->toBe('success1');

            expect($result[1])->toBeInstanceOf(SettledResult::class);
            expect($result[1]->isRejected())->toBeTrue();
            expect($result[1]->reason)->toBeInstanceOf(Exception::class);

            expect($result[2])->toBeInstanceOf(SettledResult::class);
            expect($result[2]->isFulfilled())->toBeTrue();
            expect($result[2]->value)->toBe('success2');

            expect($result[3])->toBeInstanceOf(SettledResult::class);
            expect($result[3]->isRejected())->toBeTrue();
            expect($result[3]->reason)->toBeInstanceOf(Exception::class);
        });

        it('never rejects even when all tasks fail', function () {
            $tasks = [
                fn () => delay(0.05)->then(fn () => throw new Exception('error1')),
                fn () => delay(0.05)->then(fn () => throw new Exception('error2')),
                fn () => delay(0.05)->then(fn () => throw new Exception('error3')),
            ];

            $result = Promise::concurrentSettled($tasks, 2)->wait();

            expect($result)->toHaveCount(3);

            expect($result[0]->isRejected())->toBeTrue();
            expect($result[0]->reason)->toBeInstanceOf(Exception::class);

            expect($result[1]->isRejected())->toBeTrue();
            expect($result[1]->reason)->toBeInstanceOf(Exception::class);

            expect($result[2]->isRejected())->toBeTrue();
            expect($result[2]->reason)->toBeInstanceOf(Exception::class);
        });

        it('respects concurrency limit', function () {
            $startTime = microtime(true);

            $tasks = [];
            for ($i = 0; $i < 6; $i++) {
                $tasks[] = fn () => delay(0.1)->then(fn () => "task-{$i}");
            }

            $result = Promise::concurrentSettled($tasks, 2)->wait();
            $executionTime = microtime(true) - $startTime;

            expect($result)->toHaveCount(6);
            expect($executionTime)->toBeGreaterThan(0.25); // 3 batches of 2
            expect($executionTime)->toBeLessThan(0.4);

            foreach ($result as $settledResult) {
                expect($settledResult)->toBeInstanceOf(SettledResult::class);
                expect($settledResult->isFulfilled())->toBeTrue();
            }
        });

        it('handles empty task array', function () {
            $result = Promise::concurrentSettled([], 3)->wait();

            expect($result)->toBe([]);
        });

        it('handles cancelled promises', function () {
            $cancelledPromise = new Promise();
            $cancelledPromise->cancel();
            $tasks = [
                fn () => delay(0.05)->then(fn () => 'success1'),
                fn () => $cancelledPromise,
                fn () => delay(0.05)->then(fn () => 'success2'),
            ];

            $result = Promise::concurrentSettled($tasks, 2)->wait();

            expect($result)->toHaveCount(3);

            expect($result[0]->isFulfilled())->toBeTrue();
            expect($result[0]->value)->toBe('success1');

            expect($result[1]->isCancelled())->toBeTrue();

            expect($result[2]->isFulfilled())->toBeTrue();
            expect($result[2]->value)->toBe('success2');
        });

        it('handles task that returns non-promise', function () {
            $tasks = [
                fn () => delay(0.05)->then(fn () => 'success1'),
                fn () => 'not a promise', // This will cause an error
                fn () => delay(0.05)->then(fn () => 'success2'),
            ];

            $result = Promise::concurrentSettled($tasks, 2)->wait();

            expect($result)->toHaveCount(3);

            expect($result[0]->isFulfilled())->toBeTrue();
            expect($result[0]->value)->toBe('success1');

            expect($result[1]->isRejected())->toBeTrue();
            expect($result[1]->reason)->toBeInstanceOf(RuntimeException::class);

            expect($result[2]->isFulfilled())->toBeTrue();
            expect($result[2]->value)->toBe('success2');
        });
    });

    describe('Promise::batchSettled', function () {
        it('processes tasks in batches and returns all settlement results', function () {
            $tasks = [];
            for ($i = 0; $i < 8; $i++) {
                if ($i === 3 || $i === 6) {
                    $tasks[] = fn () => delay(0.05)->then(fn () => throw new Exception("error-{$i}"));
                } else {
                    $tasks[] = fn () => delay(0.05)->then(fn () => "task-{$i}");
                }
            }

            $result = Promise::batchSettled($tasks, 3)->wait();

            expect($result)->toHaveCount(8);

            expect($result[0]->isFulfilled())->toBeTrue();
            expect($result[0]->value)->toBe('task-0');

            expect($result[3]->isRejected())->toBeTrue();
            expect($result[3]->reason)->toBeInstanceOf(Exception::class);

            expect($result[6]->isRejected())->toBeTrue();
            expect($result[6]->reason)->toBeInstanceOf(Exception::class);
        });

        it('respects batch size parameter', function () {
            $startTime = microtime(true);

            $tasks = [];
            for ($i = 0; $i < 9; $i++) {
                $tasks[] = fn () => delay(0.1)->then(fn () => "task-{$i}");
            }

            $result = Promise::batchSettled($tasks, 3)->wait();
            $executionTime = microtime(true) - $startTime;

            expect($result)->toHaveCount(9);
            expect($executionTime)->toBeGreaterThan(0.25); // 3 batches sequentially
            expect($executionTime)->toBeLessThan(0.4);

            foreach ($result as $settledResult) {
                expect($settledResult)->toBeInstanceOf(SettledResult::class);
                expect($settledResult->isFulfilled())->toBeTrue();
            }
        });

        it('respects concurrency parameter within batches', function () {
            $startTime = microtime(true);

            $tasks = [];
            for ($i = 0; $i < 6; $i++) {
                $tasks[] = fn () => delay(0.1)->then(fn () => "task-{$i}");
            }

            $result = Promise::batchSettled($tasks, 4, 2)->wait();
            $executionTime = microtime(true) - $startTime;

            expect($result)->toHaveCount(6);
            expect($executionTime)->toBeGreaterThan(0.25); // 2 batches with concurrency limit
            expect($executionTime)->toBeLessThan(0.5);

            foreach ($result as $settledResult) {
                expect($settledResult)->toBeInstanceOf(SettledResult::class);
                expect($settledResult->isFulfilled())->toBeTrue();
            }
        });

        it('never rejects even when all tasks fail', function () {
            $tasks = [
                fn () => delay(0.05)->then(fn () => throw new Exception('error1')),
                fn () => delay(0.05)->then(fn () => throw new Exception('error2')),
                fn () => delay(0.05)->then(fn () => throw new Exception('error3')),
            ];

            $result = Promise::batchSettled($tasks, 2)->wait();

            expect($result)->toHaveCount(3);

            expect($result[0]->isRejected())->toBeTrue();
            expect($result[1]->isRejected())->toBeTrue();
            expect($result[2]->isRejected())->toBeTrue();
        });

        it('handles empty task array', function () {
            $result = Promise::batchSettled([], 5)->wait();

            expect($result)->toBe([]);
        });

        it('works with batch size larger than task count', function () {
            $tasks = [
                fn () => delay(0.05)->then(fn () => 'task-0'),
                fn () => delay(0.05)->then(fn () => throw new Exception('error')),
                fn () => delay(0.05)->then(fn () => 'task-2'),
            ];

            $result = Promise::batchSettled($tasks, 10)->wait();

            expect($result)->toHaveCount(3);

            expect($result[0]->isFulfilled())->toBeTrue();
            expect($result[0]->value)->toBe('task-0');

            expect($result[1]->isRejected())->toBeTrue();
            expect($result[1]->reason)->toBeInstanceOf(Exception::class);

            expect($result[2]->isFulfilled())->toBeTrue();
            expect($result[2]->value)->toBe('task-2');
        });

        it('maintains result order within and across batches', function () {
            $tasks = [];
            for ($i = 0; $i < 7; $i++) {
                $tasks[] = function () use ($i) {
                    return delay(0.05)->then(fn () => "task-{$i}");
                };
            }

            $result = Promise::batchSettled($tasks, 3)->wait();

            expect($result)->toHaveCount(7);
            for ($i = 0; $i < 7; $i++) {
                expect($result[$i])->toBeInstanceOf(SettledResult::class);
                expect($result[$i]->isFulfilled())->toBeTrue();
                expect($result[$i]->value)->toBe("task-{$i}");
            }
        });

        it('handles cancelled promises in batches', function () {
            $cancelledPromise = new Promise();
            $cancelledPromise->cancel();
            $tasks = [
                fn () => delay(0.05)->then(fn () => 'task-0'),
                fn () => $cancelledPromise,
                fn () => delay(0.05)->then(fn () => 'task-2'),
                fn () => delay(0.05)->then(fn () => 'task-3'),
            ];

            $result = Promise::batchSettled($tasks, 2)->wait();

            expect($result)->toHaveCount(4);

            expect($result[0]->isFulfilled())->toBeTrue();
            expect($result[1]->isCancelled())->toBeTrue();
            expect($result[2]->isFulfilled())->toBeTrue();
            expect($result[3]->isFulfilled())->toBeTrue();
        });

        it('handles task that returns non-promise in batches', function () {
            $tasks = [
                fn () => delay(0.05)->then(fn () => 'task-0'),
                fn () => 'not a promise',
                fn () => delay(0.05)->then(fn () => 'task-2'),
                fn () => delay(0.05)->then(fn () => 'task-3'),
            ];

            $result = Promise::batchSettled($tasks, 2)->wait();

            expect($result)->toHaveCount(4);

            expect($result[0]->isFulfilled())->toBeTrue();
            expect($result[0]->value)->toBe('task-0');

            expect($result[1]->isRejected())->toBeTrue();
            expect($result[1]->reason)->toBeInstanceOf(RuntimeException::class);

            expect($result[2]->isFulfilled())->toBeTrue();
            expect($result[2]->value)->toBe('task-2');

            expect($result[3]->isFulfilled())->toBeTrue();
            expect($result[3]->value)->toBe('task-3');
        });
    });

    describe('Promise::timeout', function () {
        it('resolves if promise completes within timeout', function () {
            $promise = delay(0.05)->then(fn () => 'success');

            $result = Promise::timeout($promise, 0.1)->wait();

            expect($result)->toBe('success');
        });

        it('rejects if promise exceeds timeout', function () {
            try {
                $promise = delay(0.2)->then(fn () => 'too slow');

                Promise::timeout($promise, 0.1)->wait();
                expect(false)->toBeTrue('Expected timeout exception');
            } catch (Exception $e) {
                expect($e)->toBeInstanceOf(Exception::class);
            }
        });

        it('rejects immediately if original promise rejects', function () {
            try {
                $promise = delay(0.05)->then(fn () => throw new Exception('original error'));

                Promise::timeout($promise, 0.1)->wait();
                expect(false)->toBeTrue('Expected original exception');
            } catch (Exception $e) {
                expect($e->getMessage())->toBe('original error');
            }
        });
    });

    describe('Promise::resolved', function () {
        it('creates a resolved promise with the given value', function () {
            $promise = Promise::resolved('test value');

            expect($promise->isFulfilled())->toBeTrue();
            expect($promise->value)->toBe('test value');
        });
    });

    describe('Promise::rejected', function () {
        it('creates a rejected promise with the given reason', function () {
            $exception = new Exception('test error');
            $promise = Promise::rejected($exception);

            expect($promise->isRejected())->toBeTrue();
            expect($promise->reason)->toBe($exception);
        });
    });

    describe('Promise::map', function () {
        it('maps values using a callback function', function () {
            $items = [1, 2, 3];
            $result = Promise::map($items, fn ($i) => $i * 2)->wait();

            expect($result)->toBe([2, 4, 6]);
        });

        it('waits for promises returned by the mapper', function () {
            $items = [1, 2, 3];
            $result = Promise::map($items, function ($i) {
                return delay(0.01)->then(fn () => $i * 2);
            })->wait();

            expect($result)->toBe([2, 4, 6]);
        });

        it('resolves input promises before passing to mapper', function () {
            $items = [
                10,
                Promise::resolved(20),
                delay(0.05)->then(fn () => 30),
            ];

            $result = Promise::map($items, function ($val) {
                return $val + 1;
            })->wait();

            expect($result)->toBe([11, 21, 31]);
        });

        it('respects concurrency limits', function () {
            $startTime = microtime(true);
            $items = [1, 2, 3, 4];

            Promise::map($items, fn () => delay(0.1), 2)->wait();

            $executionTime = microtime(true) - $startTime;

            expect($executionTime)->toBeGreaterThan(0.2);
            expect($executionTime)->toBeLessThan(0.35);
        });

        it('treats null concurrency as unlimited', function () {
            $startTime = microtime(true);
            $items = array_fill(0, 10, null);

            Promise::map($items, fn () => delay(0.1), null)->wait();

            $executionTime = microtime(true) - $startTime;

            expect($executionTime)->toBeLessThan(0.2);
        });

        it('passes keys to the mapper', function () {
            $items = ['a' => 1, 'b' => 2];

            $result = Promise::map($items, function ($val, $key) {
                return "{$key}:{$val}";
            })->wait();

            expect($result)->toBe(['a' => 'a:1', 'b' => 'b:2']);
        });

        it('preserves order of results even if they resolve out of order', function () {
            $items = [0.1, 0.3, 0.05];

            $result = Promise::map($items, function ($time) {
                return delay($time)->then(fn () => $time);
            })->wait();

            expect($result)->toBe([0.1, 0.3, 0.05]);
        });

        it('rejects immediately if a mapper throws exception', function () {
            try {
                $items = [1, 2, 3];
                Promise::map($items, function ($i) {
                    if ($i === 2) {
                        throw new Exception('map error');
                    }

                    return $i;
                })->wait();
                expect(false)->toBeTrue('Expected exception');
            } catch (Exception $e) {
                expect($e->getMessage())->toBe('map error');
            }
        });

        it('works with generators/iterables', function () {
            $generator = function () {
                yield 'a' => 1;
                yield 'b' => 2;
            };

            $result = Promise::map($generator(), fn ($i) => $i * 10)->wait();

            expect($result)->toBe(['a' => 10, 'b' => 20]);
        });
    });

    describe('Promise::mapSettled', function () {
        it('returns fulfilled results for all successful items', function () {
            $items = [1, 2, 3];
            $result = Promise::mapSettled($items, fn ($i) => $i * 2)->wait();

            expect($result)->toHaveCount(3);
            expect($result[0]->isFulfilled())->toBeTrue();
            expect($result[0]->value)->toBe(2);
            expect($result[1]->isFulfilled())->toBeTrue();
            expect($result[1]->value)->toBe(4);
            expect($result[2]->isFulfilled())->toBeTrue();
            expect($result[2]->value)->toBe(6);
        });

        it('captures rejections without rejecting the outer promise', function () {
            $items = [1, 2, 3];
            $result = Promise::mapSettled($items, function ($i) {
                if ($i === 2) {
                    throw new Exception('map error');
                }

                return $i * 2;
            })->wait();

            expect($result)->toHaveCount(3);
            expect($result[0]->isFulfilled())->toBeTrue();
            expect($result[0]->value)->toBe(2);
            expect($result[1]->isRejected())->toBeTrue();
            expect($result[1]->reason->getMessage())->toBe('map error');
            expect($result[2]->isFulfilled())->toBeTrue();
            expect($result[2]->value)->toBe(6);
        });

        it('fulfills outer promise even when all items reject', function () {
            $items = [1, 2, 3];
            $result = Promise::mapSettled($items, function ($i) {
                throw new Exception("error-{$i}");
            })->wait();

            expect($result)->toHaveCount(3);
            expect($result[0]->isRejected())->toBeTrue();
            expect($result[1]->isRejected())->toBeTrue();
            expect($result[2]->isRejected())->toBeTrue();
        });

        it('waits for promises returned by the mapper', function () {
            $items = [1, 2, 3];
            $result = Promise::mapSettled($items, function ($i) {
                return delay(0.01)->then(fn () => $i * 2);
            })->wait();

            expect($result)->toHaveCount(3);
            expect($result[0]->isFulfilled())->toBeTrue();
            expect($result[0]->value)->toBe(2);
            expect($result[1]->isFulfilled())->toBeTrue();
            expect($result[1]->value)->toBe(4);
            expect($result[2]->isFulfilled())->toBeTrue();
            expect($result[2]->value)->toBe(6);
        });

        it('resolves input promises before passing to mapper', function () {
            $items = [
                10,
                Promise::resolved(20),
                delay(0.05)->then(fn () => 30),
            ];

            $result = Promise::mapSettled($items, fn ($val) => $val + 1)->wait();

            expect($result)->toHaveCount(3);
            expect($result[0]->isFulfilled())->toBeTrue();
            expect($result[0]->value)->toBe(11);
            expect($result[1]->isFulfilled())->toBeTrue();
            expect($result[1]->value)->toBe(21);
            expect($result[2]->isFulfilled())->toBeTrue();
            expect($result[2]->value)->toBe(31);
        });

        it('captures rejected input promises as rejected results', function () {
            $items = [
                Promise::resolved(10),
                Promise::rejected(new Exception('Input rejected')),
                Promise::resolved(30),
            ];

            $result = Promise::mapSettled($items, fn ($val) => $val * 2)->wait();

            expect($result)->toHaveCount(3);
            expect($result[0]->isFulfilled())->toBeTrue();
            expect($result[0]->value)->toBe(20);
            expect($result[1]->isRejected())->toBeTrue();
            expect($result[1]->reason->getMessage())->toBe('Input rejected');
            expect($result[2]->isFulfilled())->toBeTrue();
            expect($result[2]->value)->toBe(60);
        });

        it('respects concurrency limits', function () {
            $startTime = microtime(true);
            $items = [1, 2, 3, 4];

            Promise::mapSettled($items, fn () => delay(0.1), 2)->wait();

            $executionTime = microtime(true) - $startTime;

            expect($executionTime)->toBeGreaterThan(0.2);
            expect($executionTime)->toBeLessThan(0.35);
        });

        it('treats null concurrency as unlimited', function () {
            $startTime = microtime(true);
            $items = array_fill(0, 10, null);

            Promise::mapSettled($items, fn () => delay(0.1), null)->wait();

            $executionTime = microtime(true) - $startTime;

            expect($executionTime)->toBeLessThan(0.2);
        });

        it('passes keys to the mapper', function () {
            $items = ['a' => 1, 'b' => 2];

            $result = Promise::mapSettled($items, fn ($val, $key) => "{$key}:{$val}")->wait();

            expect($result['a']->isFulfilled())->toBeTrue();
            expect($result['a']->value)->toBe('a:1');
            expect($result['b']->isFulfilled())->toBeTrue();
            expect($result['b']->value)->toBe('b:2');
        });

        it('preserves order of results even if they resolve out of order', function () {
            $items = [0.1, 0.3, 0.05];

            $result = Promise::mapSettled($items, fn ($time) => delay($time)->then(fn () => $time))->wait();

            expect(array_keys($result))->toBe([0, 1, 2]);
            expect($result[0]->value)->toBe(0.1);
            expect($result[1]->value)->toBe(0.3);
            expect($result[2]->value)->toBe(0.05);
        });

        it('handles empty input', function () {
            $result = Promise::mapSettled([], fn ($i) => $i)->wait();

            expect($result)->toBe([]);
        });

        it('works with generators/iterables', function () {
            $generator = function () {
                yield 'a' => 1;
                yield 'b' => 2;
                yield 'c' => 3;
            };

            $result = Promise::mapSettled($generator(), function ($i, $key) {
                if ($key === 'b') {
                    throw new Exception("failed at {$key}");
                }

                return $i * 10;
            })->wait();

            expect($result['a']->isFulfilled())->toBeTrue();
            expect($result['a']->value)->toBe(10);
            expect($result['b']->isRejected())->toBeTrue();
            expect($result['b']->reason->getMessage())->toBe('failed at b');
            expect($result['c']->isFulfilled())->toBeTrue();
            expect($result['c']->value)->toBe(30);
        });
    });

    describe('Promise::filter', function () {
        it('returns only items that pass the predicate', function () {
            $items = [1, 2, 3, 4, 5, 6];
            $result = Promise::filter($items, fn ($n) => $n % 2 === 0)->wait();

            expect($result)->toBe([1 => 2, 3 => 4, 5 => 6]);
        });

        it('returns all items when all pass the predicate', function () {
            $result = Promise::filter([1, 2, 3], fn ($n) => true)->wait();

            expect($result)->toBe([1, 2, 3]);
        });

        it('returns empty array when no items pass', function () {
            $result = Promise::filter([1, 2, 3], fn ($n) => false)->wait();

            expect($result)->toBe([]);
        });

        it('handles empty input', function () {
            $result = Promise::filter([], fn ($n) => true)->wait();

            expect($result)->toBe([]);
        });

        it('works with async predicate', function () {
            $result = Promise::filter(
                [1, 2, 3, 4, 5],
                fn (int $n) => delay(0.01)->then(fn () => $n > 3)
            )->wait();

            expect($result)->toBe([3 => 4, 4 => 5]);
        });

        it('resolves input promises before passing to predicate', function () {
            $items = [
                10,
                Promise::resolved(20),
                delay(0.05)->then(fn () => 30),
            ];

            $result = Promise::filter($items, fn (int $n) => $n >= 20)->wait();

            expect(array_values($result))->toBe([20, 30]);
        });

        it('preserves string keys', function () {
            $result = Promise::filter(
                ['alice' => 30, 'bob' => 17, 'charlie' => 25, 'dave' => 15],
                fn (int $age) => $age >= 18
            )->wait();

            expect($result)->toBe(['alice' => 30, 'charlie' => 25]);
            expect(array_keys($result))->toBe(['alice', 'charlie']);
        });

        it('preserves non-sequential numeric keys', function () {
            $result = Promise::filter(
                [10 => 'ten', 20 => 'twenty', 30 => 'thirty'],
                fn (string $v, int $k) => $k !== 20
            )->wait();

            expect(array_keys($result))->toBe([10, 30]);
            expect($result)->toBe([10 => 'ten', 30 => 'thirty']);
        });

        it('passes keys to the predicate', function () {
            $capturedKeys = [];

            Promise::filter(
                ['a' => 1, 'b' => 2, 'c' => 3],
                function (int $n, string $key) use (&$capturedKeys) {
                    $capturedKeys[] = $key;

                    return true;
                }
            )->wait();

            expect($capturedKeys)->toBe(['a', 'b', 'c']);
        });

        it('preserves order even when async predicates resolve out of order', function () {
            $items = [0.1, 0.3, 0.05];

            $result = Promise::filter(
                $items,
                fn (float $time) => delay($time)->then(fn () => true)
            )->wait();

            expect(array_keys($result))->toBe([0, 1, 2]);
        });

        it('respects concurrency limits', function () {
            $startTime = microtime(true);
            $items = [1, 2, 3, 4];

            Promise::filter($items, fn () => delay(0.1)->then(fn () => true), 2)->wait();

            $executionTime = microtime(true) - $startTime;

            expect($executionTime)->toBeGreaterThan(0.2);
            expect($executionTime)->toBeLessThan(0.35);
        });

        it('treats null concurrency as unlimited', function () {
            $startTime = microtime(true);
            $items = array_fill(0, 10, null);

            Promise::filter($items, fn () => delay(0.1)->then(fn () => true), null)->wait();

            $executionTime = microtime(true) - $startTime;

            expect($executionTime)->toBeLessThan(0.2);
        });

        it('works with generators', function () {
            $generator = function () {
                yield 'a' => 1;
                yield 'b' => 2;
                yield 'c' => 3;
            };

            $result = Promise::filter($generator(), fn (int $n) => $n !== 2)->wait();

            expect(array_keys($result))->toBe(['a', 'c']);
            expect(array_values($result))->toBe([1, 3]);
        });

        it('rejects immediately if predicate throws', function () {
            try {
                Promise::filter(
                    [1, 2, 3],
                    function (int $n) {
                        if ($n === 2) {
                            throw new Exception('filter error');
                        }

                        return true;
                    }
                )->wait();
                expect(false)->toBeTrue('Expected exception');
            } catch (Exception $e) {
                expect($e->getMessage())->toBe('filter error');
            }
        });

        it('rejects immediately if predicate returns rejected promise', function () {
            try {
                Promise::filter(
                    [1, 2, 3],
                    fn (int $n) => $n === 2
                        ? Promise::rejected(new Exception('async filter error'))
                        : Promise::resolved(true)
                )->wait();
                expect(false)->toBeTrue('Expected exception');
            } catch (Exception $e) {
                expect($e->getMessage())->toBe('async filter error');
            }
        });

        it('treats predicate errors as false when using catch', function () {
            $result = Promise::filter(
                [1, 2, 3, 4, 5],
                function (int $n) {
                    if ($n === 3) {
                        return Promise::rejected(new Exception('Unavailable'))
                            ->catch(fn () => false)
                        ;
                    }

                    return Promise::resolved($n % 2 === 0);
                }
            )->wait();

            expect(array_values($result))->toBe([2, 4]);
        });

        it('chains naturally with map and reduce', function () {
            $result = Promise::filter(
                [1, 2, 3, 4, 5, 6],
                fn (int $n) => $n % 2 === 0
            )->then(fn (array $evens) => Promise::map(
                $evens,
                fn (int $n) => Promise::resolved($n * 10)
            ))->then(fn (array $mapped) => Promise::reduce(
                $mapped,
                fn (int $carry, int $n) => $carry + $n,
                0
            ))->wait();

            // evens: [2, 4, 6] → mapped: [20, 40, 60] → sum: 120
            expect($result)->toBe(120);
        });
    });

    describe('Promise::reduce', function () {
        it('reduces integers to a sum with synchronous reducer', function () {
            $result = Promise::reduce(
                [1, 2, 3, 4, 5],
                fn (int $carry, int $n) => $carry + $n,
                0
            )->wait();

            expect($result)->toBe(15);
        });

        it('reduces with an async reducer', function () {
            $result = Promise::reduce(
                [1, 2, 3, 4, 5],
                fn (int $carry, int $n) => delay(0.01)->then(fn () => $carry + $n),
                0
            )->wait();

            expect($result)->toBe(15);
        });

        it('resolves input promises before passing to reducer', function () {
            $result = Promise::reduce(
                [
                    10,
                    Promise::resolved(20),
                    delay(0.05)->then(fn () => 30),
                ],
                fn (int $carry, int $n) => $carry + $n,
                0
            )->wait();

            expect($result)->toBe(60);
        });

        it('passes key as third argument to reducer', function () {
            $capturedKeys = [];

            Promise::reduce(
                ['a' => 1, 'b' => 2, 'c' => 3],
                function (int $carry, int $n, string $key) use (&$capturedKeys) {
                    $capturedKeys[] = $key;

                    return $carry + $n;
                },
                0
            )->wait();

            expect($capturedKeys)->toBe(['a', 'b', 'c']);
        });

        it('returns initial value for empty input', function () {
            $result = Promise::reduce([], fn ($carry, $n) => $carry + $n, 99)->wait();

            expect($result)->toBe(99);
        });

        it('executes sequentially', function () {
            $executionOrder = [];

            Promise::reduce(
                [1, 2, 3],
                function (array $carry, int $n) use (&$executionOrder) {
                    $executionOrder[] = "step_{$n}_carry_" . count($carry);
                    $carry[] = $n;

                    return delay(0.01)->then(fn () => $carry);
                },
                []
            )->wait();

            expect($executionOrder)->toBe([
                'step_1_carry_0',
                'step_2_carry_1',
                'step_3_carry_2',
            ]);
        });

        it('works with generators', function () {
            $generator = function () {
                yield 'a' => 1;
                yield 'b' => 2;
                yield 'c' => 3;
            };

            $result = Promise::reduce(
                $generator(),
                fn (int $carry, int $n) => $carry + $n,
                0
            )->wait();

            expect($result)->toBe(6);
        });

        it('rejects immediately if reducer throws', function () {
            try {
                Promise::reduce(
                    [1, 2, 3],
                    function (int $carry, int $n) {
                        if ($n === 2) {
                            throw new Exception('reduce error');
                        }

                        return $carry + $n;
                    },
                    0
                )->wait();
                expect(false)->toBeTrue('Expected exception');
            } catch (Exception $e) {
                expect($e->getMessage())->toBe('reduce error');
            }
        });

        it('rejects immediately if reducer returns rejected promise', function () {
            try {
                Promise::reduce(
                    [1, 2, 3],
                    fn (int $carry, int $n) => $n === 2
                        ? Promise::rejected(new Exception('async reduce error'))
                        : Promise::resolved($carry + $n),
                    0
                )->wait();
                expect(false)->toBeTrue('Expected exception');
            } catch (Exception $e) {
                expect($e->getMessage())->toBe('async reduce error');
            }
        });

        it('chains naturally after filter and map', function () {
            $result = Promise::filter(
                [1, 2, 3, 4, 5, 6],
                fn (int $n) => $n % 2 === 0
            )->then(fn (array $evens) => Promise::map(
                $evens,
                fn (int $n) => Promise::resolved($n * 10)
            ))->then(fn (array $mapped) => Promise::reduce(
                $mapped,
                fn (int $carry, int $n) => $carry + $n,
                0
            ))->wait();

            // evens: [2, 4, 6] → mapped: [20, 40, 60] → sum: 120
            expect($result)->toBe(120);
        });
    });

    describe('Promise::forEach', function () {
        it('executes callback for each item as a side effect', function () {
            $visited = [];

            Promise::forEach(
                [1, 2, 3, 4, 5],
                function (int $n) use (&$visited) {
                    $visited[] = $n;
                }
            )->wait();

            expect($visited)->toBe([1, 2, 3, 4, 5]);
        });

        it('passes keys to the callback', function () {
            $capturedKeys = [];

            Promise::forEach(
                ['a' => 1, 'b' => 2, 'c' => 3],
                function (int $n, string $key) use (&$capturedKeys) {
                    $capturedKeys[] = $key;
                }
            )->wait();

            expect($capturedKeys)->toBe(['a', 'b', 'c']);
        });

        it('resolves input promises before passing to callback', function () {
            $received = [];

            Promise::forEach(
                [
                    10,
                    Promise::resolved(20),
                    delay(0.05)->then(fn () => 30),
                ],
                function (int $n) use (&$received) {
                    $received[] = $n;
                }
            )->wait();

            expect($received)->toBe([10, 20, 30]);
        });

        it('rejects immediately if callback throws', function () {
            try {
                Promise::forEach(
                    [1, 2, 3],
                    function (int $n) {
                        if ($n === 2) {
                            throw new Exception('forEach error');
                        }
                    }
                )->wait();
                expect(false)->toBeTrue('Expected exception');
            } catch (Exception $e) {
                expect($e->getMessage())->toBe('forEach error');
            }
        });

        it('rejects immediately if callback returns a rejected promise', function () {
            try {
                Promise::forEach(
                    [1, 2, 3],
                    function (int $n) {
                        if ($n === 2) {
                            return Promise::rejected(new Exception('async forEach error'));
                        }

                        return Promise::resolved(null);
                    }
                )->wait();
                expect(false)->toBeTrue('Expected exception');
            } catch (Exception $e) {
                expect($e->getMessage())->toBe('async forEach error');
            }
        });

        it('handles empty input', function () {
            $called = false;

            Promise::forEach([], function () use (&$called) {
                $called = true;
            })->wait();

            expect($called)->toBeFalse();
        });

        it('respects concurrency limits', function () {
            $startTime = microtime(true);
            $items = [1, 2, 3, 4];

            Promise::forEach($items, fn () => delay(0.1), 2)->wait();

            $executionTime = microtime(true) - $startTime;

            expect($executionTime)->toBeGreaterThan(0.2);
            expect($executionTime)->toBeLessThan(0.35);
        });

        it('treats null concurrency as unlimited', function () {
            $startTime = microtime(true);
            $items = array_fill(0, 10, null);

            Promise::forEach($items, fn () => delay(0.1), null)->wait();

            $executionTime = microtime(true) - $startTime;

            expect($executionTime)->toBeLessThan(0.2);
        });

        it('works with generators', function () {
            $count = 0;

            Promise::forEach(
                (function () {
                    yield 'a' => 1;
                    yield 'b' => 2;
                    yield 'c' => 3;
                })(),
                function (int $n) use (&$count) {
                    $count++;
                }
            )->wait();

            expect($count)->toBe(3);
        });

        it('does not accumulate results — memory stays flat', function () {
            $memBefore = memory_get_usage(true);

            Promise::forEach(
                (function () {
                    for ($i = 0; $i < 10_000; $i++) {
                        yield $i;
                    }
                })(),
                fn (int $n) => Promise::resolved(null),
                concurrency: 500
            )->wait();

            $bytesPerItem = (memory_get_usage(true) - $memBefore) / 10_000;

            expect($bytesPerItem)->toBeLessThan(1000);
        });

        it('stops processing remaining items after first failure', function () {
            $processed = [];

            try {
                Promise::forEach(
                    [1, 2, 3, 4, 5],
                    function (int $n) use (&$processed) {
                        if ($n === 2) {
                            throw new Exception('stop here');
                        }
                        $processed[] = $n;
                    },
                    concurrency: 1 // serial so order is deterministic
                )->wait();
            } catch (Exception) {
                // expected
            }

            expect($processed)->not->toContain(3);
            expect($processed)->not->toContain(4);
            expect($processed)->not->toContain(5);
        });
    });

    describe('Promise::forEachSettled', function () {
        it('executes callback for all items regardless of failures', function () {
            $processed = [];

            Promise::forEachSettled(
                [1, 2, 3, 4, 5],
                function (int $n) use (&$processed) {
                    $processed[] = $n;
                    if ($n === 2 || $n === 4) {
                        throw new Exception("Failed at $n");
                    }
                }
            )->wait();

            expect($processed)->toBe([1, 2, 3, 4, 5]);
        });

        it('outer promise always fulfills even when all callbacks throw', function () {
            $outerFulfilled = false;

            Promise::forEachSettled(
                [1, 2, 3],
                fn (int $n) => throw new Exception("Always fails: $n")
            )->then(function () use (&$outerFulfilled) {
                $outerFulfilled = true;
            })->wait();

            expect($outerFulfilled)->toBeTrue();
        });

        it('outer promise always fulfills even when all callbacks return rejected promises', function () {
            $outerFulfilled = false;

            Promise::forEachSettled(
                [1, 2, 3],
                fn (int $n) => Promise::rejected(new Exception("Rejected: $n"))
            )->then(function () use (&$outerFulfilled) {
                $outerFulfilled = true;
            })->wait();

            expect($outerFulfilled)->toBeTrue();
        });

        it('passes keys to the callback', function () {
            $capturedKeys = [];

            Promise::forEachSettled(
                ['a' => 1, 'b' => 2, 'c' => 3],
                function (int $n, string $key) use (&$capturedKeys) {
                    $capturedKeys[] = $key;
                    if ($key === 'b') {
                        throw new Exception("Failed at $key");
                    }
                }
            )->wait();

            expect($capturedKeys)->toBe(['a', 'b', 'c']);
        });

        it('resolves input promises before passing to callback', function () {
            $received = [];

            Promise::forEachSettled(
                [
                    10,
                    Promise::resolved(20),
                    delay(0.05)->then(fn () => 30),
                ],
                function (int $n) use (&$received) {
                    $received[] = $n;
                }
            )->wait();

            expect($received)->toBe([10, 20, 30]);
        });

        it('handles empty input', function () {
            $called = false;

            Promise::forEachSettled([], function () use (&$called) {
                $called = true;
            })->wait();

            expect($called)->toBeFalse();
        });

        it('respects concurrency limits', function () {
            $startTime = microtime(true);
            $items = [1, 2, 3, 4];

            Promise::forEachSettled($items, fn () => delay(0.1), 2)->wait();

            $executionTime = microtime(true) - $startTime;

            expect($executionTime)->toBeGreaterThan(0.2);
            expect($executionTime)->toBeLessThan(0.35);
        });

        it('treats null concurrency as unlimited', function () {
            $startTime = microtime(true);
            $items = array_fill(0, 10, null);

            Promise::forEachSettled($items, fn () => delay(0.1), null)->wait();

            $executionTime = microtime(true) - $startTime;

            expect($executionTime)->toBeLessThan(0.2);
        });

        it('works with generators even with mixed failures', function () {
            $count = 0;

            Promise::forEachSettled(
                (function () {
                    yield 'a' => 1;
                    yield 'b' => 2;
                    yield 'c' => 3;
                })(),
                function (int $n, string $key) use (&$count) {
                    $count++;
                    if ($key === 'b') {
                        throw new Exception("Failed at $key");
                    }
                }
            )->wait();

            expect($count)->toBe(3);
        });

        it('does not accumulate results — memory stays flat', function () {
            $memBefore = memory_get_usage(true);

            Promise::forEachSettled(
                (function () {
                    for ($i = 0; $i < 10_000; $i++) {
                        yield $i;
                    }
                })(),
                function (int $n) {
                    if ($n % 500 === 0) {
                        throw new Exception("Simulated failure at $n");
                    }

                    return Promise::resolved(null);
                },
                concurrency: 500
            )->wait();

            gc_collect_cycles();

            $bytesPerItem = (memory_get_usage(true) - $memBefore) / 10_000;

            expect($bytesPerItem)->toBeLessThan(1);
        });

        it('continues processing all items after async rejection', function () {
            $processed = [];

            Promise::forEachSettled(
                [1, 2, 3, 4, 5],
                function (int $n) use (&$processed) {
                    return delay(0.01)->then(function () use ($n, &$processed) {
                        $processed[] = $n;
                        if ($n === 2) {
                            throw new Exception('async failure');
                        }
                    });
                },
                concurrency: 1 // serial so count is deterministic
            )->wait();

            expect($processed)->toHaveCount(5);
        });
    });
});
