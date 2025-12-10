<?php

declare(strict_types=1);

use Hibla\Promise\Promise;

describe('Promise Static Factories', function () {
    it('creates resolved promise with resolved()', function () {
        $promise = Promise::resolved('test value');

        expect($promise->isFulfilled())->toBeTrue()
            ->and($promise->getValue())->toBe('test value')
        ;
    });

    it('creates rejected promise with rejected()', function () {
        $exception = new Exception('test error');
        $promise = Promise::rejected($exception);

        expect($promise->isRejected())->toBeTrue()
            ->and($promise->getReason())->toBe($exception)
        ;
    });
});
