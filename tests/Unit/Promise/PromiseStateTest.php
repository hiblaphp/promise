<?php

declare(strict_types=1);

use Hibla\Promise\Promise;

describe('Promise State Management', function () {
    it('creates a pending promise with no executor', function () {
        $promise = new Promise();

        expect($promise->isPending())->toBeTrue()
            ->and($promise->isResolved())->toBeFalse()
            ->and($promise->isRejected())->toBeFalse()
        ;
    });

    it('can be resolved with a value', function () {
        $promise = new Promise();
        $promise->resolve('test value');

        expect($promise->isResolved())->toBeTrue()
            ->and($promise->isPending())->toBeFalse()
            ->and($promise->isRejected())->toBeFalse()
            ->and($promise->getValue())->toBe('test value')
        ;
    });

    it('can be rejected with a reason', function () {
        $promise = new Promise();
        $exception = new Exception('test error');
        $promise->reject($exception);

        expect($promise->isRejected())->toBeTrue()
            ->and($promise->isPending())->toBeFalse()
            ->and($promise->isResolved())->toBeFalse()
            ->and($promise->getReason())->toBe($exception)
        ;
    });

    it('ignores multiple resolution attempts', function () {
        $promise = new Promise();
        $promise->resolve('first value');
        $promise->resolve('second value');

        expect($promise->getValue())->toBe('first value');
    });

    it('ignores multiple rejection attempts', function () {
        $promise = new Promise();
        $firstError = new Exception('first error');
        $secondError = new Exception('second error');

        $promise->reject($firstError);
        $promise->reject($secondError);

        expect($promise->getReason())->toBe($firstError);
    });

    it('ignores resolution after rejection', function () {
        $promise = new Promise();
        $exception = new Exception('error');

        $promise->reject($exception);
        $promise->resolve('value');

        expect($promise->isRejected())->toBeTrue()
            ->and($promise->getReason())->toBe($exception)
        ;
    });

    it('ignores rejection after resolution', function () {
        $promise = new Promise();
        $promise->resolve('value');
        $promise->reject(new Exception('error'));

        expect($promise->isResolved())->toBeTrue()
            ->and($promise->getValue())->toBe('value')
        ;
    });
});
