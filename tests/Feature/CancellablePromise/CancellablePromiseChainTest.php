<?php

declare(strict_types=1);

use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;

describe('CancellablePromise Chaining', function () {
    it('supports promise chaining', function () {
        $promise = new CancellablePromise();

        $chainedPromise = $promise->then(function ($value) {
            return $value.' processed';
        });

        $promise->resolve('initial');

        expect($chainedPromise)->toBeInstanceOf(PromiseInterface::class);
        expect($chainedPromise)->toBeInstanceOf(CancellablePromiseInterface::class);
    });

    it('handles cancellation in promise chain', function () {
        $promise = new CancellablePromise();
        $thenCalled = false;
        $catchCalled = false;

        $chainedPromise = $promise->then(function ($value) use (&$thenCalled) {
            $thenCalled = true;

            return $value.' processed';
        })->catch(function ($reason) use (&$catchCalled) {
            $catchCalled = true;

            return 'caught: '.$reason->getMessage();
        });

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue()
            ->and($promise->isRejected())->toBeTrue()
        ;
        expect($chainedPromise)->toBeInstanceOf(PromiseInterface::class);
    });

    it('maintains cancellation state through chain', function () {
        $promise = new CancellablePromise();

        $chainedPromise = $promise->then(function ($value) {
            return $value.' processed';
        });

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();

        if (method_exists($chainedPromise, 'getRootCancellable')) {
            expect($chainedPromise->getRootCancellable())->toBe($promise);
        }
    });

    it('can set finally callback', function () {
        $promise = new CancellablePromise();
        $finallyCalled = false;

        $finalPromise = $promise->finally(function () use (&$finallyCalled) {
            $finallyCalled = true;
        });

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
        expect($finalPromise)->toBeInstanceOf(PromiseInterface::class);
    });
});
