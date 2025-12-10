<?php

declare(strict_types=1);

use Hibla\Promise\Promise;

describe('Promise Value Handling', function () {
    it('can be resolved with null', function () {
        $promise = new Promise();
        $promise->resolve(null);

        expect($promise->isFulfilled())->toBeTrue()
            ->and($promise->getValue())->toBeNull()
        ;
    });

    it('can be resolved with complex data types', function () {
        $promise = new Promise();
        $data = ['key' => 'value', 'nested' => ['array' => true]];
        $promise->resolve($data);

        expect($promise->getValue())->toBe($data);
    });

    it('can be rejected with string reasons', function () {
        $promise = new Promise();
        $promise->reject('simple error message');

        expect($promise->getReason())->toBeInstanceOf(Exception::class)
            ->and($promise->getReason()->getMessage())->toBe('simple error message')
        ;
    });

    it('returns null when getting value of non-resolved promise', function () {
        $promise = new Promise();
        expect($promise->getValue())->toBeNull();
    });

    it('returns null when getting reason of non-rejected promise', function () {
        $promise = new Promise();
        expect($promise->getReason())->toBeNull();
    });
});
