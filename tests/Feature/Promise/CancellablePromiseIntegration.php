<?php

declare(strict_types=1);

use Hibla\Promise\CancellablePromise;

describe('CancellablePromise Integration', function () {
    it('tracks root cancellable promise in chain', function () {
        $cancellable = new CancellablePromise();
        $chained = $cancellable->then(function ($value) {
            return $value * 2;
        });

        expect($chained->getRootCancellable())->toBe($cancellable);
    });

    it('skips handlers when root promise is cancelled', function () {
        $cancellable = new CancellablePromise();
        $handlerCalled = false;

        $chained = $cancellable->then(function ($value) use (&$handlerCalled) {
            $handlerCalled = true;

            return $value;
        });

        $cancellable->cancel();
        $cancellable->resolve('test'); // This won't trigger handlers due to cancellation

        expect($handlerCalled)->toBeFalse();
    });
});
