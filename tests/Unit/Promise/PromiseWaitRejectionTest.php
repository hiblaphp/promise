<?php

declare(strict_types=1);

use Hibla\Promise\Exceptions\PromiseRejectionException;
use Hibla\Promise\Promise;

describe('Promise wait() rejection handling', function () {
    it('throws PromiseRejectionException when rejected with a string reason', function () {
        $promise = Promise::rejected('something went wrong');

        expect(fn () => $promise->wait())
            ->toThrow(PromiseRejectionException::class, 'something went wrong')
        ;
    });

    it('throws PromiseRejectionException when rejected with an integer reason', function () {
        $promise = Promise::rejected(404);

        expect(fn () => $promise->wait())
            ->toThrow(PromiseRejectionException::class)
        ;
    });

    it('throws PromiseRejectionException when rejected with an array reason', function () {
        $promise = Promise::rejected(['code' => 500, 'message' => 'server error']);

        expect(fn () => $promise->wait())
            ->toThrow(PromiseRejectionException::class)
        ;
    });

    it('rethrows the original Throwable when rejected with an exception', function () {
        $exception = new RuntimeException('original error');
        $promise = Promise::rejected($exception);

        expect(fn () => $promise->wait())
            ->toThrow(RuntimeException::class, 'original error')
        ;
    });

    it('rethrows the original Throwable when rejected with a custom exception', function () {
        $exception = new InvalidArgumentException('bad argument');
        $promise = Promise::rejected($exception);

        expect(fn () => $promise->wait())
            ->toThrow(InvalidArgumentException::class, 'bad argument')
        ;
    });
});
