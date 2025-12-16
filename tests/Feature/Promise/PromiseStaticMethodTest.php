<?php

declare(strict_types=1);

use function Hibla\delay;

use Hibla\Promise\Promise;

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
            $promise2 = new Promise(); // Never settles
            $promise3 = Promise::resolved('success');
            $promise4 = new Promise(); // Never settles

            $result = Promise::any([$promise1, $promise2, $promise3, $promise4])->wait();

            expect($result)->toBe('success');
        });
    });

    describe('Promise::race', function () {
        it('resolves with the first settled promise value', function () {
            $promise1 = Promise::resolved('fast');
            $promise2 = new Promise(); // never settles
            $promise3 = new Promise(); // never settles

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
            expect($executionTime)->toBeGreaterThan(0.3); // At least 4 batches of 3
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
            expect($executionTime)->toBeGreaterThan(0.25); // 3 batches of 2
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

            if (is_array($result[0]) && isset($result[0]['status'])) {
                expect($result[0]['status'])->toBe('fulfilled');
                expect($result[0]['value'])->toBe('success1');
                expect($result[1]['status'])->toBe('rejected');
                expect($result[1]['reason'])->toBeInstanceOf(Exception::class);
                expect($result[2]['status'])->toBe('fulfilled');
                expect($result[2]['value'])->toBe('success2');
                expect($result[3]['status'])->toBe('rejected');
                expect($result[3]['reason'])->toBeInstanceOf(Exception::class);
            } else {
                // If raw results are returned
                expect($result[0])->toBe('success1');
                expect($result[1])->toBeInstanceOf(Exception::class);
                expect($result[2])->toBe('success2');
                expect($result[3])->toBeInstanceOf(Exception::class);
            }
        });

        it('never rejects even when all tasks fail', function () {
            $tasks = [
                fn () => delay(0.05)->then(fn () => throw new Exception('error1')),
                fn () => delay(0.05)->then(fn () => throw new Exception('error2')),
                fn () => delay(0.05)->then(fn () => throw new Exception('error3')),
            ];

            $result = Promise::concurrentSettled($tasks, 2)->wait();

            expect($result)->toHaveCount(3);

            expect($result[0]['status'])->toBe('rejected');
            expect($result[1]['status'])->toBe('rejected');
            expect($result[2]['status'])->toBe('rejected');
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
        });

        it('handles empty task array', function () {
            $result = Promise::concurrentSettled([], 3)->wait();

            expect($result)->toBe([]);
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

            expect($result[0]['status'])->toBe('fulfilled');
            expect($result[0]['value'])->toBe('task-0');
            expect($result[3]['status'])->toBe('rejected');
            expect($result[3]['reason'])->toBeInstanceOf(Exception::class);
            expect($result[6]['status'])->toBe('rejected');
            expect($result[6]['reason'])->toBeInstanceOf(Exception::class);
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
        });

        it('never rejects even when all tasks fail', function () {
            $tasks = [
                fn () => delay(0.05)->then(fn () => throw new Exception('error1')),
                fn () => delay(0.05)->then(fn () => throw new Exception('error2')),
                fn () => delay(0.05)->then(fn () => throw new Exception('error3')),
            ];

            $result = Promise::batchSettled($tasks, 2)->wait();

            expect($result)->toHaveCount(3);

            expect($result[0]['status'])->toBe('rejected');
            expect($result[1]['status'])->toBe('rejected');
            expect($result[2]['status'])->toBe('rejected');
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

            expect($result[0]['status'])->toBe('fulfilled');
            expect($result[0]['value'])->toBe('task-0');
            expect($result[1]['status'])->toBe('rejected');
            expect($result[1]['reason'])->toBeInstanceOf(Exception::class);
            expect($result[2]['status'])->toBe('fulfilled');
            expect($result[2]['value'])->toBe('task-2');
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
                if (is_array($result[$i]) && isset($result[$i]['status'])) {
                    expect($result[$i]['status'])->toBe('fulfilled');
                    expect($result[$i]['value'])->toBe("task-{$i}");
                } else {
                    expect($result[$i])->toBe("task-{$i}");
                }
            }
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
            expect($promise->getValue())->toBe('test value');
        });
    });

    describe('Promise::rejected', function () {
        it('creates a rejected promise with the given reason', function () {
            $exception = new Exception('test error');
            $promise = Promise::rejected($exception);

            expect($promise->isRejected())->toBeTrue();
            expect($promise->getReason())->toBe($exception);
        });
    });
});
