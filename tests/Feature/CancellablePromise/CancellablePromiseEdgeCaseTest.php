<?php

use Hibla\Promise\CancellablePromise;

describe('CancellablePromise Edge Cases', function () {
    it('handles rapid cancel and resolve attempts', function () {
        $promise = new CancellablePromise();

        $promise->cancel();
        $promise->resolve('value');
        $promise->cancel();
        $promise->reject(new Exception('error'));

        expect($promise->isCancelled())->toBeTrue()
            ->and($promise->isRejected())->toBeTrue()
            ->and($promise->isResolved())->toBeFalse()
        ;
    });

    it('handles multiple cancel handlers', function () {
        $promise = new CancellablePromise();
        $callCount = 0;

        $promise->setCancelHandler(function () use (&$callCount) {
            $callCount += 1;
        });
        $promise->setCancelHandler(function () use (&$callCount) {
            $callCount += 10;
        });

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
        expect($callCount)->toBe(10);
    });

    it('maintains state consistency under stress', function () {
        $promises = [];

        for ($i = 0; $i < 10; $i++) {
            $promises[] = new CancellablePromise();
        }

        foreach ($promises as $promise) {
            $promise->cancel();
        }

        foreach ($promises as $promise) {
            expect($promise->isCancelled())->toBeTrue();
        }
    });
});
