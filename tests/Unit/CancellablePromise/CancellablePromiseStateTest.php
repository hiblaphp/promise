<?php

declare(strict_types=1);

use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;

describe('CancellablePromise State Management', function () {
    it('implements CancellablePromiseInterface', function () {
        $promise = new CancellablePromise();

        expect($promise)->toBeInstanceOf(CancellablePromiseInterface::class);
    });

    it('starts in pending state', function () {
        $promise = new CancellablePromise();

        expect($promise->isPending())->toBeTrue()
            ->and($promise->isResolved())->toBeFalse()
            ->and($promise->isRejected())->toBeFalse()
            ->and($promise->isCancelled())->toBeFalse()
        ;
    });

    it('can be resolved with a value', function () {
        $promise = new CancellablePromise();
        $testValue = 'test result';

        $promise->resolve($testValue);

        expect($promise->isResolved())->toBeTrue()
            ->and($promise->isPending())->toBeFalse()
            ->and($promise->isRejected())->toBeFalse()
            ->and($promise->getValue())->toBe($testValue)
        ;
    });

    it('can be rejected with a reason', function () {
        $promise = new CancellablePromise();
        $testReason = new Exception('test error');

        $promise->reject($testReason);

        expect($promise->isRejected())->toBeTrue()
            ->and($promise->isPending())->toBeFalse()
            ->and($promise->isResolved())->toBeFalse()
            ->and($promise->getReason())->toBe($testReason)
        ;
    });

    it('can be cancelled', function () {
        $promise = new CancellablePromise();

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue()
            ->and($promise->isRejected())->toBeTrue()
            ->and($promise->isPending())->toBeFalse()
        ;
    });

    it('becomes rejected when cancelled', function () {
        $promise = new CancellablePromise();

        $promise->cancel();

        expect($promise->isRejected())->toBeTrue();
        expect($promise->getReason())->toBeInstanceOf(Exception::class);
        expect($promise->getReason()->getMessage())->toBe('Promise cancelled');
    });

    it('cannot be resolved after cancellation', function () {
        $promise = new CancellablePromise();

        $promise->cancel();
        $promise->resolve('test value');

        expect($promise->isCancelled())->toBeTrue()
            ->and($promise->isResolved())->toBeFalse()
            ->and($promise->isRejected())->toBeTrue()
        ;
    });

    it('cannot be rejected after cancellation', function () {
        $promise = new CancellablePromise();

        $promise->cancel();
        $promise->reject(new Exception('new error'));

        expect($promise->isCancelled())->toBeTrue()
            ->and($promise->isRejected())->toBeTrue()
        ;
        expect($promise->getReason()->getMessage())->toBe('Promise cancelled');
    });

    it('ignores multiple cancellation attempts', function () {
        $promise = new CancellablePromise();
        $cancelCount = 0;

        $promise->setCancelHandler(function () use (&$cancelCount) {
            $cancelCount++;
        });

        $promise->cancel();
        $promise->cancel();
        $promise->cancel();

        expect($cancelCount)->toBe(1)
            ->and($promise->isCancelled())->toBeTrue()
        ;
    });
});
