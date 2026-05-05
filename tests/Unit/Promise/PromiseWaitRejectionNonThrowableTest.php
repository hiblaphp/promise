<?php

declare(strict_types=1);

use Hibla\Promise\Exceptions\PromiseRejectionException;
use Hibla\Promise\Promise;

describe('Promise wait() non-Throwable rejection reasons', function () {

    it('wraps a string reason in PromiseRejectionException', function () {
        $promise = Promise::rejected('something went wrong');

        expect(fn () => $promise->wait())
            ->toThrow(PromiseRejectionException::class, 'something went wrong')
        ;
    });

    it('preserves the original string reason on the exception', function () {
        $promise = Promise::rejected('something went wrong');

        try {
            $promise->wait();
        } catch (PromiseRejectionException $e) {
            expect($e->reason)->toBe('something went wrong');
        }
    });

    it('wraps an integer reason in PromiseRejectionException', function () {
        $promise = Promise::rejected(404);

        try {
            $promise->wait();
        } catch (PromiseRejectionException $e) {
            expect($e)->toBeInstanceOf(PromiseRejectionException::class);
            expect($e->reason)->toBe(404);
        }
    });

    it('wraps an array reason in PromiseRejectionException and preserves it', function () {
        $reason = ['code' => 500, 'message' => 'server error'];
        $promise = Promise::rejected($reason);

        try {
            $promise->wait();
        } catch (PromiseRejectionException $e) {
            expect($e)->toBeInstanceOf(PromiseRejectionException::class);
            expect($e->reason)->toBe($reason);
        }
    });

    it('wraps a null reason in PromiseRejectionException', function () {
        $promise = Promise::rejected(null);

        try {
            $promise->wait();
        } catch (PromiseRejectionException $e) {
            expect($e)->toBeInstanceOf(PromiseRejectionException::class);
            expect($e->reason)->toBeNull();
        }
    });

    it('wraps a stdClass reason and preserves the original object', function () {
        $obj = new stdClass();
        $obj->error = 'object error';
        $promise = Promise::rejected($obj);

        try {
            $promise->wait();
        } catch (PromiseRejectionException $e) {
            expect($e)->toBeInstanceOf(PromiseRejectionException::class);
            expect($e->reason)->toBeInstanceOf(stdClass::class);
            expect($e->reason->error)->toBe('object error');
        }
    });
});

describe('Promise wait() Throwable rejection reasons', function () {

    it('rethrows a RuntimeException directly without wrapping', function () {
        $original = new RuntimeException('original error');
        $promise = Promise::rejected($original);

        expect(fn () => $promise->wait())
            ->toThrow(RuntimeException::class, 'original error')
        ;
    });

    it('does not wrap a Throwable in PromiseRejectionException', function () {
        $promise = Promise::rejected(new RuntimeException('original error'));

        try {
            $promise->wait();
        } catch (RuntimeException $e) {
            expect($e)->not->toBeInstanceOf(PromiseRejectionException::class);
            expect($e->getMessage())->toBe('original error');
        }
    });

    it('rethrows a custom exception type directly', function () {
        $promise = Promise::rejected(new InvalidArgumentException('bad argument'));

        expect(fn () => $promise->wait())
            ->toThrow(InvalidArgumentException::class, 'bad argument')
        ;
    });
});
