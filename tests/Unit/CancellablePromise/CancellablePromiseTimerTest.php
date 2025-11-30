<?php

declare(strict_types=1);

use Hibla\Promise\CancellablePromise;

describe('CancellablePromise Timer Management', function () {
    it('sets and uses timer ID for cancellation', function () {
        $promise = new CancellablePromise();
        $timerId = 'test-timer-123';

        $promise->setTimerId($timerId);
        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
    });

    it('works with different timer ID formats', function () {
        $promise = new CancellablePromise();
        $promise->setTimerId('simple-id');
        $promise->setTimerId('complex-id-123-456');
        $promise->setTimerId('uuid-like-f47ac10b-58cc-4372-a567-0e02b2c3d479');

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
    });

    it('can handle cancellation with empty timer ID', function () {
        $promise = new CancellablePromise();

        $promise->setTimerId('');
        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
    });
});
