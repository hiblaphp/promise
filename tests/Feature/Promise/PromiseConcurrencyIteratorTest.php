<?php

use Hibla\Promise\Promise;

describe("Concurrency Iterator Graceful Rejection", function () {
    test('batch rejects gracefully when iterator throws synchronously', function () {
        $generator = function () {
            yield function () {
                return Promise::resolved('ok');
            };
            throw new RuntimeException('Iterator Failure in batch');
        };

        $promise = Promise::batch($generator(), batchSize: 2);

        expect(fn() => $promise->wait())
            ->toThrow(RuntimeException::class, 'Iterator Failure in batch');
    });

    test('batchSettled rejects gracefully when iterator throws synchronously', function () {
        $generator = function () {
            yield function () {
                return Promise::resolved('ok');
            };
            throw new RuntimeException('Iterator Failure in batchSettled');
        };

        $promise = Promise::batchSettled($generator(), batchSize: 2);

        expect(fn() => $promise->wait())
            ->toThrow(RuntimeException::class, 'Iterator Failure in batchSettled');
    });

    test('concurrent rejects gracefully when iterator throws synchronously', function () {
        $generator = function () {
            yield function () {
                return Promise::resolved(1);
            };
            throw new RuntimeException('Iterator Failure in concurrent');
        };

        $promise = Promise::concurrent($generator(), concurrency: 2);

        expect(fn() => $promise->wait())
            ->toThrow(RuntimeException::class, 'Iterator Failure in concurrent');
    });

    test('concurrentSettled rejects gracefully when iterator throws synchronously', function () {
        $generator = function () {
            yield function () {
                return Promise::resolved(1);
            };
            throw new RuntimeException('Iterator Failure in concurrentSettled');
        };

        $promise = Promise::concurrentSettled($generator(), concurrency: 2);

        expect(fn() => $promise->wait())
            ->toThrow(RuntimeException::class, 'Iterator Failure in concurrentSettled');
    });

    test('concurrent handles task setup failure gracefully', function () {
        $tasks = [
            function () {
                return Promise::resolved(1);
            },
            function () {
                throw new Exception('Task Setup Failed');
            }
        ];

        $promise = Promise::concurrent($tasks, concurrency: 2);

        expect(fn() => $promise->wait())
            ->toThrow(Exception::class, 'Task Setup Failed');
    });
});
