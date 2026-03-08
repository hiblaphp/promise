<?php

declare(strict_types=1);

use Hibla\Promise\Promise;

describe('Concurrency Iterator Graceful Rejection', function () {
    test('batch rejects gracefully when iterator throws synchronously', function () {
        $generator = function () {
            yield function () {
                return Promise::resolved('ok');
            };

            throw new RuntimeException('Iterator Failure in batch');
        };

        $promise = Promise::batch($generator(), batchSize: 2);

        expect(fn () => $promise->wait())
            ->toThrow(RuntimeException::class, 'Iterator Failure in batch')
        ;
    });

    test('batchSettled rejects gracefully when iterator throws synchronously', function () {
        $generator = function () {
            yield function () {
                return Promise::resolved('ok');
            };

            throw new RuntimeException('Iterator Failure in batchSettled');
        };

        $promise = Promise::batchSettled($generator(), batchSize: 2);

        expect(fn () => $promise->wait())
            ->toThrow(RuntimeException::class, 'Iterator Failure in batchSettled')
        ;
    });

    test('concurrent rejects gracefully when iterator throws synchronously', function () {
        $generator = function () {
            yield function () {
                return Promise::resolved(1);
            };

            throw new RuntimeException('Iterator Failure in concurrent');
        };

        $promise = Promise::concurrent($generator(), concurrency: 2);

        expect(fn () => $promise->wait())
            ->toThrow(RuntimeException::class, 'Iterator Failure in concurrent')
        ;
    });

    test('concurrentSettled rejects gracefully when iterator throws synchronously', function () {
        $generator = function () {
            yield function () {
                return Promise::resolved(1);
            };

            throw new RuntimeException('Iterator Failure in concurrentSettled');
        };

        $promise = Promise::concurrentSettled($generator(), concurrency: 2);

        expect(fn () => $promise->wait())
            ->toThrow(RuntimeException::class, 'Iterator Failure in concurrentSettled')
        ;
    });

    test('concurrent handles task setup failure gracefully', function () {
        $tasks = [
            function () {
                return Promise::resolved(1);
            },
            function () {
                throw new Exception('Task Setup Failed');
            },
        ];

        $promise = Promise::concurrent($tasks, concurrency: 2);

        expect(fn () => $promise->wait())
            ->toThrow(Exception::class, 'Task Setup Failed')
        ;
    });

    test('map rejects gracefully when iterator throws synchronously', function () {
        $generator = function () {
            yield 1;
            yield 2;

            throw new RuntimeException('Iterator Failure in map');
        };

        $promise = Promise::map(
            $generator(),
            fn (int $n) => Promise::resolved($n * 2),
            concurrency: 2
        );

        expect(fn () => $promise->wait())
            ->toThrow(RuntimeException::class, 'Iterator Failure in map')
        ;
    });

    test('map rejects gracefully when mapper throws synchronously', function () {
        $tasks = [1, 2, 3];

        $promise = Promise::map(
            $tasks,
            function (int $n) {
                if ($n === 2) {
                    throw new Exception('Mapper Setup Failed');
                }

                return Promise::resolved($n);
            },
            concurrency: 2
        );

        expect(fn () => $promise->wait())
            ->toThrow(Exception::class, 'Mapper Setup Failed')
        ;
    });

    test('map rejects gracefully when mapper returns a rejected promise', function () {
        $promise = Promise::map(
            [1, 2, 3],
            fn (int $n) => $n === 2
                ? Promise::rejected(new RuntimeException('Async Mapper Failure'))
                : Promise::resolved($n),
            concurrency: 2
        );

        expect(fn () => $promise->wait())
            ->toThrow(RuntimeException::class, 'Async Mapper Failure')
        ;
    });

    test('mapSettled fulfills gracefully when iterator throws synchronously', function () {
        $generator = function () {
            yield 1;
            yield 2;

            throw new RuntimeException('Iterator Failure in mapSettled');
        };

        $promise = Promise::mapSettled(
            $generator(),
            fn (int $n) => Promise::resolved($n * 2),
            concurrency: 2
        );

        expect(fn () => $promise->wait())
            ->toThrow(RuntimeException::class, 'Iterator Failure in mapSettled')
        ;
    });

    test('mapSettled captures mapper throws as rejected results without rejecting outer promise', function () {
        $promise = Promise::mapSettled(
            [1, 2, 3],
            function (int $n) {
                if ($n === 2) {
                    throw new Exception('Mapper Setup Failed');
                }

                return Promise::resolved($n);
            },
            concurrency: 2
        );

        $results = $promise->wait();

        expect($results[0]->isFulfilled())->toBeTrue();
        expect($results[1]->isRejected())->toBeTrue();
        expect($results[1]->reason->getMessage())->toBe('Mapper Setup Failed');
        expect($results[2]->isFulfilled())->toBeTrue();
    });

    test('mapSettled captures async mapper rejections without rejecting outer promise', function () {
        $promise = Promise::mapSettled(
            [1, 2, 3],
            fn (int $n) => $n === 2
                ? Promise::rejected(new RuntimeException('Async Mapper Failure'))
                : Promise::resolved($n),
            concurrency: 2
        );

        $results = $promise->wait();

        expect($results[0]->isFulfilled())->toBeTrue();
        expect($results[1]->isRejected())->toBeTrue();
        expect($results[1]->reason->getMessage())->toBe('Async Mapper Failure');
        expect($results[2]->isFulfilled())->toBeTrue();
    });

    test('forEach rejects gracefully when iterator throws synchronously', function () {
        $generator = function () {
            yield 1;
            yield 2;

            throw new RuntimeException('Iterator Failure in forEach');
        };

        $promise = Promise::forEach(
            $generator(),
            fn (int $n) => Promise::resolved(null),
            concurrency: 2
        );

        expect(fn () => $promise->wait())
            ->toThrow(RuntimeException::class, 'Iterator Failure in forEach')
        ;
    });

    test('forEach rejects gracefully when callback throws synchronously', function () {
        $promise = Promise::forEach(
            [1, 2, 3],
            function (int $n) {
                if ($n === 2) {
                    throw new Exception('Callback Setup Failed');
                }
            },
            concurrency: 2
        );

        expect(fn () => $promise->wait())
            ->toThrow(Exception::class, 'Callback Setup Failed')
        ;
    });

    test('forEach rejects gracefully when callback returns a rejected promise', function () {
        $promise = Promise::forEach(
            [1, 2, 3],
            fn (int $n) => $n === 2
                ? Promise::rejected(new RuntimeException('Async Callback Failure'))
                : Promise::resolved(null),
            concurrency: 2
        );

        expect(fn () => $promise->wait())
            ->toThrow(RuntimeException::class, 'Async Callback Failure')
        ;
    });

    test('forEachSettled rejects gracefully when iterator throws synchronously', function () {
        $generator = function () {
            yield 1;
            yield 2;

            throw new RuntimeException('Iterator Failure in forEachSettled');
        };

        $promise = Promise::forEachSettled(
            $generator(),
            fn (int $n) => Promise::resolved(null),
            concurrency: 2
        );

        expect(fn () => $promise->wait())
            ->toThrow(RuntimeException::class, 'Iterator Failure in forEachSettled')
        ;
    });

    test('forEachSettled silently swallows synchronous callback throws', function () {
        $processed = [];
        $outerFulfilled = false;

        Promise::forEachSettled(
            [1, 2, 3],
            function (int $n) use (&$processed) {
                $processed[] = $n;
                if ($n === 2) {
                    throw new Exception('Callback Setup Failed');
                }
            },
            concurrency: 2
        )->then(function () use (&$outerFulfilled) {
            $outerFulfilled = true;
        })->wait();

        expect($outerFulfilled)->toBeTrue();
        expect($processed)->toHaveCount(3);
    });

    test('forEachSettled silently swallows async callback rejections', function () {
        $outerFulfilled = false;

        Promise::forEachSettled(
            [1, 2, 3],
            fn (int $n) => $n === 2
                ? Promise::rejected(new RuntimeException('Async Callback Failure'))
                : Promise::resolved(null),
            concurrency: 2
        )->then(function () use (&$outerFulfilled) {
            $outerFulfilled = true;
        })->wait();

        expect($outerFulfilled)->toBeTrue();
    });
});
