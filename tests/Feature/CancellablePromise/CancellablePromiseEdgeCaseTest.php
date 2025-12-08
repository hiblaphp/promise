<?php

declare(strict_types=1);

use Hibla\Promise\Promise;

describe('Promise Edge Cases', function () {
    it('handles rapid cancel and resolve attempts', function () {
        $promise = new Promise();

        $promise->cancel();
        $promise->resolve('value');
        $promise->cancel();
        $promise->reject(new Exception('error'));

        expect($promise->isCancelled())->toBeTrue()
            ->and($promise->isRejected())->toBeFalse()
            ->and($promise->isResolved())->toBeFalse()
        ;
    });

    it('handles multiple cancel handlers', function () {
        $promise = new Promise();
        $callCount = 0;

        $promise->onCancel(function () use (&$callCount) {
            $callCount += 1;
        });
        $promise->onCancel(function () use (&$callCount) {
            $callCount += 10;
        });

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
        expect($callCount)->toBe(11);
    });

    it('maintains state consistency under stress', function () {
        $promises = [];

        for ($i = 0; $i < 10; $i++) {
            $promises[] = new Promise();
        }

        foreach ($promises as $promise) {
            $promise->cancel();
        }

        foreach ($promises as $promise) {
            expect($promise->isCancelled())->toBeTrue();
        }
    });
});
