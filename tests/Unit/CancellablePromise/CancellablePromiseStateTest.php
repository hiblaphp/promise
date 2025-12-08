<?php

declare(strict_types=1);

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

describe('Promise State Management', function () {
    it('implements PromiseInterface', function () {
        $promise = new Promise();

        expect($promise)->toBeInstanceOf(PromiseInterface::class);
    });

    it('starts in pending state', function () {
        $promise = new Promise();

        expect($promise->isPending())->toBeTrue()
            ->and($promise->isResolved())->toBeFalse()
            ->and($promise->isRejected())->toBeFalse()
            ->and($promise->isCancelled())->toBeFalse()
        ;
    });

    it('can be resolved with a value', function () {
        $promise = new Promise();
        $testValue = 'test result';

        $promise->resolve($testValue);

        expect($promise->isResolved())->toBeTrue()
            ->and($promise->isPending())->toBeFalse()
            ->and($promise->isRejected())->toBeFalse()
            ->and($promise->getValue())->toBe($testValue)
        ;
    });

    it('can be rejected with a reason', function () {
        $promise = new Promise();
        $testReason = new Exception('test error');

        $promise->reject($testReason);

        expect($promise->isRejected())->toBeTrue()
            ->and($promise->isPending())->toBeFalse()
            ->and($promise->isResolved())->toBeFalse()
            ->and($promise->getReason())->toBe($testReason)
        ;
    });

    it('can be cancelled', function () {
        $promise = new Promise();

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue()
            ->and($promise->isRejected())->toBeFalse()
            ->and($promise->isPending())->toBeFalse()
        ;
    });

    it('cannot be rejected when cancelled', function () {
        $promise = new Promise();

        $promise->cancel();

        expect($promise->isRejected())->toBeFalse();
        expect($promise->getReason())->toBeNull();
        expect($promise->getReason())->toBeNull();
    });

    it('cannot be resolved after cancellation', function () {
        $promise = new Promise();

        $promise->cancel();
        $promise->resolve('test value');

        expect($promise->isCancelled())->toBeTrue()
            ->and($promise->isResolved())->toBeFalse()
            ->and($promise->isRejected())->toBeFalse()
        ;
    });

    it('cannot be rejected after cancellation', function () {
        $promise = new Promise();

        $promise->cancel();
        $promise->reject(new Exception('new error'));

        expect($promise->isCancelled())->toBeTrue()
            ->and($promise->isRejected())->toBeFalse()
        ;
        expect($promise->getReason())->toBeNull();
    });

    it('ignores multiple cancellation attempts', function () {
        $promise = new Promise();
        $cancelCount = 0;

        $promise->onCancel(function () use (&$cancelCount) {
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
