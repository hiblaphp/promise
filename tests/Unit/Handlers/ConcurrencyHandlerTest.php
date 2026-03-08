<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Promise\Handlers\ConcurrencyHandler;
use Hibla\Promise\Promise;

describe('ConcurrencyHandler', function () {
    it('runs tasks concurrently', function () {
        $handler = new ConcurrencyHandler();
        $tasks = [
            fn () => Promise::resolved('result1'),
            fn () => Promise::resolved('result2'),
            fn () => Promise::resolved('result3'),
        ];

        $results = $handler->concurrent($tasks, 2)->wait();

        expect($results)->toBe(['result1', 'result2', 'result3']);
    });

    it('respects concurrency limit', function () {
        $handler = new ConcurrencyHandler();
        $counter = 0;
        $maxConcurrent = 0;

        $tasks = array_fill(0, 5, function () use (&$counter, &$maxConcurrent) {
            $counter++;
            $maxConcurrent = max($maxConcurrent, $counter);

            return new Promise(function ($resolve) use (&$counter) {
                usleep(10000);
                $counter--;
                $resolve('done');
            });
        });

        $handler->concurrent($tasks, 2)->wait();

        expect($maxConcurrent)->toBeLessThanOrEqual(2);
    });

    it('handles empty task array', function () {
        $handler = new ConcurrencyHandler();
        $results = $handler->concurrent([])->wait();

        expect($results)->toBe([]);
    });

    it('preserves array keys', function () {
        $handler = new ConcurrencyHandler();
        $tasks = [
            'task1' => fn () => Promise::resolved('result1'),
            'task2' => fn () => Promise::resolved('result2'),
        ];

        $results = $handler->concurrent($tasks)->wait();

        expect($results)->toBe([
            'task1' => 'result1',
            'task2' => 'result2',
        ]);
    });

    it('handles task exceptions', function () {
        $handler = new ConcurrencyHandler();
        $tasks = [
            fn () => Promise::resolved('success'),
            fn () => Promise::rejected(new Exception('task failed')),
        ];

        expect(fn () => $handler->concurrent($tasks)->wait())
            ->toThrow(Exception::class, 'task failed')
        ;
    });

    it('runs batch processing', function () {
        $handler = new ConcurrencyHandler();
        $tasks = array_fill(0, 5, fn () => Promise::resolved('result'));

        $results = $handler->batch($tasks, 2)->wait();

        expect($results)->toHaveCount(5);
        expect(array_unique($results))->toBe(['result']);
    });

    it('handles concurrent settled operations', function () {
        $handler = new ConcurrencyHandler();
        $tasks = [
            fn () => Promise::resolved('success'),
            fn () => Promise::rejected(new Exception('failure')),
            fn () => Promise::resolved('another success'),
        ];

        $results = $handler->concurrentSettled($tasks)->wait();

        expect($results)->toHaveCount(3);
        expect($results[0]->isFulfilled())->toBeTrue();
        expect($results[0]->value)->toBe('success');
        expect($results[1]->isRejected())->toBeTrue();
        expect($results[2]->isFulfilled())->toBeTrue();
    });

    it('validates concurrency parameter', function () {
        $handler = new ConcurrencyHandler();

        expect(fn () => $handler->concurrent([], 0)->wait())
            ->toThrow(InvalidArgumentException::class, 'Concurrency limit must be greater than 0')
        ;

        expect(fn () => $handler->concurrent([], -1)->wait())
            ->toThrow(InvalidArgumentException::class, 'Concurrency limit must be greater than 0')
        ;
    });

    describe('map', function () {
        it('transforms each item with the mapper', function () {
            $handler = new ConcurrencyHandler();

            $results = $handler->map(
                [1, 2, 3, 4, 5],
                fn (int $n) => Promise::resolved($n * 10)
            )->wait();

            expect($results)->toBe([10, 20, 30, 40, 50]);
        });

        it('preserves string keys', function () {
            $handler = new ConcurrencyHandler();

            $results = $handler->map(
                ['a' => 1, 'b' => 2, 'c' => 3],
                fn (int $n) => Promise::resolved($n * 2)
            )->wait();

            expect($results)->toBe(['a' => 2, 'b' => 4, 'c' => 6]);
        });

        it('passes key as second argument to mapper', function () {
            $handler = new ConcurrencyHandler();
            $capturedKeys = [];

            $handler->map(
                ['x' => 10, 'y' => 20],
                function (int $n, string $key) use (&$capturedKeys) {
                    $capturedKeys[] = $key;
                    return Promise::resolved($n);
                }
            )->wait();

            expect($capturedKeys)->toBe(['x', 'y']);
        });

        it('resolves promise items before passing to mapper', function () {
            $handler = new ConcurrencyHandler();

            $results = $handler->map(
                [Promise::resolved(5), Promise::resolved(10)],
                fn (int $n) => Promise::resolved($n * 2)
            )->wait();

            expect($results)->toBe([10, 20]);
        });

        it('rejects if any mapper invocation rejects', function () {
            $handler = new ConcurrencyHandler();

            expect(fn () => $handler->map(
                [1, 2, 3],
                function (int $n) {
                    if ($n === 2) {
                        return Promise::rejected(new RuntimeException('Rejected at 2'));
                    }
                    return Promise::resolved($n);
                }
            )->wait())->toThrow(RuntimeException::class, 'Rejected at 2');
        });

        it('rejects if mapper throws synchronously', function () {
            $handler = new ConcurrencyHandler();

            expect(fn () => $handler->map(
                [1, 2, 3],
                function (int $n) {
                    if ($n === 2) {
                        throw new RuntimeException('Sync throw at 2');
                    }
                    return Promise::resolved($n);
                }
            )->wait())->toThrow(RuntimeException::class, 'Sync throw at 2');
        });

        it('handles empty input', function () {
            $handler = new ConcurrencyHandler();

            $results = $handler->map([], fn ($n) => Promise::resolved($n))->wait();

            expect($results)->toBe([]);
        });

        it('consumes a generator without materializing it', function () {
            $handler = new ConcurrencyHandler();

            $gen = (function () {
                yield 'first'  => 1;
                yield 'second' => 2;
                yield 'third'  => 3;
            })();

            $results = $handler->map(
                $gen,
                fn (int $n) => Promise::resolved($n * 10)
            )->wait();

            expect($results)->toBe(['first' => 10, 'second' => 20, 'third' => 30]);
        });

        it('respects the concurrency cap', function () {
            $handler = new ConcurrencyHandler();
            $peak = 0;
            $running = 0;

            $handler->map(
                range(1, 6),
                function (int $n) use (&$peak, &$running) {
                    $running++;
                    $peak = max($peak, $running);

                    return new Promise(function ($resolve) use ($n, &$running) {
                        Loop::addTimer(0.001, function () use ($n, $resolve, &$running) {
                            $running--;
                            $resolve($n);
                        });
                    });
                },
                concurrency: 2
            )->wait();

            expect($peak)->toBeLessThanOrEqual(2);
        });
    });

    // -------------------------------------------------------------------------
    // mapSettled()
    // -------------------------------------------------------------------------

    describe('mapSettled', function () {
        it('returns fulfilled results for all successful items', function () {
            $handler = new ConcurrencyHandler();

            $results = $handler->mapSettled(
                [1, 2, 3],
                fn (int $n) => Promise::resolved($n * 10)
            )->wait();

            expect($results)->toHaveCount(3);
            expect($results[0]->isFulfilled())->toBeTrue();
            expect($results[0]->value)->toBe(10);
            expect($results[1]->isFulfilled())->toBeTrue();
            expect($results[1]->value)->toBe(20);
            expect($results[2]->isFulfilled())->toBeTrue();
            expect($results[2]->value)->toBe(30);
        });

        it('captures rejections without rejecting the outer promise', function () {
            $handler = new ConcurrencyHandler();

            $results = $handler->mapSettled(
                [1, 2, 3],
                function (int $n) {
                    if ($n === 2) {
                        return Promise::rejected(new RuntimeException('Rejected at 2'));
                    }
                    return Promise::resolved($n * 10);
                }
            )->wait();

            expect($results)->toHaveCount(3);
            expect($results[0]->isFulfilled())->toBeTrue();
            expect($results[0]->value)->toBe(10);
            expect($results[1]->isRejected())->toBeTrue();
            expect($results[1]->reason->getMessage())->toBe('Rejected at 2');
            expect($results[2]->isFulfilled())->toBeTrue();
            expect($results[2]->value)->toBe(30);
        });

        it('fulfills outer promise even when all items reject', function () {
            $handler = new ConcurrencyHandler();

            $results = $handler->mapSettled(
                ['a', 'b', 'c'],
                fn (string $s) => Promise::rejected(new RuntimeException("Failed: $s"))
            )->wait();

            expect($results)->toHaveCount(3);
            expect($results[0]->isRejected())->toBeTrue();
            expect($results[1]->isRejected())->toBeTrue();
            expect($results[2]->isRejected())->toBeTrue();
        });

        it('captures synchronous mapper throws as rejected results', function () {
            $handler = new ConcurrencyHandler();

            $results = $handler->mapSettled(
                [1, 2, 3],
                function (int $n) {
                    if ($n === 2) {
                        throw new RuntimeException('Sync throw at 2');
                    }
                    return Promise::resolved($n * 10);
                }
            )->wait();

            expect($results[0]->isFulfilled())->toBeTrue();
            expect($results[1]->isRejected())->toBeTrue();
            expect($results[1]->reason->getMessage())->toBe('Sync throw at 2');
            expect($results[2]->isFulfilled())->toBeTrue();
        });

        it('resolves promise items before passing to mapper', function () {
            $handler = new ConcurrencyHandler();

            $results = $handler->mapSettled(
                [Promise::resolved(5), Promise::rejected(new RuntimeException('Input rejected')), Promise::resolved(15)],
                fn (int $n) => Promise::resolved($n * 2)
            )->wait();

            expect($results[0]->isFulfilled())->toBeTrue();
            expect($results[0]->value)->toBe(10);
            expect($results[1]->isRejected())->toBeTrue();
            expect($results[1]->reason->getMessage())->toBe('Input rejected');
            expect($results[2]->isFulfilled())->toBeTrue();
            expect($results[2]->value)->toBe(30);
        });

        it('preserves string keys', function () {
            $handler = new ConcurrencyHandler();

            $results = $handler->mapSettled(
                ['foo' => 1, 'bar' => 2],
                fn (int $n) => Promise::resolved($n * 10)
            )->wait();

            expect($results)->toHaveKeys(['foo', 'bar']);
            expect($results['foo']->value)->toBe(10);
            expect($results['bar']->value)->toBe(20);
        });

        it('handles empty input', function () {
            $handler = new ConcurrencyHandler();

            $results = $handler->mapSettled([], fn ($n) => Promise::resolved($n))->wait();

            expect($results)->toBe([]);
        });

        it('consumes a generator without materializing it', function () {
            $handler = new ConcurrencyHandler();

            $gen = (function () {
                yield 'first'  => 1;
                yield 'second' => 2;
                yield 'third'  => 3;
            })();

            $results = $handler->mapSettled(
                $gen,
                function (int $n, string $key) {
                    if ($key === 'second') {
                        return Promise::rejected(new RuntimeException("Rejected at: $key"));
                    }
                    return Promise::resolved("$key=$n");
                }
            )->wait();

            expect($results['first']->isFulfilled())->toBeTrue();
            expect($results['first']->value)->toBe('first=1');
            expect($results['second']->isRejected())->toBeTrue();
            expect($results['second']->reason->getMessage())->toBe('Rejected at: second');
            expect($results['third']->isFulfilled())->toBeTrue();
            expect($results['third']->value)->toBe('third=3');
        });

        it('respects the concurrency cap', function () {
            $handler = new ConcurrencyHandler();
            $peak = 0;
            $running = 0;

            $handler->mapSettled(
                range(1, 6),
                function (int $n) use (&$peak, &$running) {
                    $running++;
                    $peak = max($peak, $running);

                    return new Promise(function ($resolve) use ($n, &$running) {
                        Loop::addTimer(0.001, function () use ($n, $resolve, &$running) {
                            $running--;
                            $resolve($n);
                        });
                    });
                },
                concurrency: 2
            )->wait();

            expect($peak)->toBeLessThanOrEqual(2);
        });

        it('result order matches input order regardless of completion order', function () {
            $handler = new ConcurrencyHandler();

            $results = $handler->mapSettled(
                [3, 2, 1],
                function (int $n) {
                    return new Promise(function ($resolve) use ($n) {
                        Loop::addTimer($n * 0.001, fn () => $resolve($n));
                    });
                }
            )->wait();

            expect(array_keys($results))->toBe([0, 1, 2]);
            expect($results[0]->value)->toBe(3);
            expect($results[1]->value)->toBe(2);
            expect($results[2]->value)->toBe(1);
        });
    });
});