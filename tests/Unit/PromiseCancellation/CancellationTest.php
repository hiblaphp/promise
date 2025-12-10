<?php

declare(strict_types=1);

use function Hibla\delay;

use Hibla\EventLoop\Loop;

use Hibla\Promise\Promise;

describe('Promise Cancellation', function () {

    afterEach(function () {
        Loop::reset();
    });

    test('pending promise can be cancelled', function () {
        $promise = new Promise();

        expect($promise->isPending())->toBeTrue();

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
        expect($promise->isFulfilled())->toBeFalse();
        expect($promise->isRejected())->toBeFalse();
        expect($promise->isSettled())->toBeFalse();
        expect($promise->isPending())->toBeFalse();
    });

    test('resolved promise cannot be cancelled', function () {
        $promise = new Promise(function ($resolve) {
            $resolve('test value');
        });

        Loop::run();

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->isSettled())->toBeTrue();

        $promise->cancel();

        expect($promise->isCancelled())->toBeFalse();
        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->isRejected())->toBeFalse();
        expect($promise->isSettled())->toBeTrue();
        expect($promise->isPending())->toBeFalse();
    });

    test('rejected promise cannot be cancelled', function () {
        $promise = new Promise(function ($resolve, $reject) {
            $reject(new Exception('test error'));
        });

        // Need to add a catch handler to prevent unhandled rejection warning
        $promise->catch(fn ($e) => $e);

        Loop::run(); // Let the promise settle

        expect($promise->isRejected())->toBeTrue();
        expect($promise->isSettled())->toBeTrue();

        $promise->cancel();

        expect($promise->isCancelled())->toBeFalse();
        expect($promise->isFulfilled())->toBeFalse();
        expect($promise->isRejected())->toBeTrue();
        expect($promise->isSettled())->toBeTrue();
        expect($promise->isPending())->toBeFalse();
    });

    test('cancel handler is executed when pending promise is cancelled', function () {
        $handlerCalled = false;

        $promise = new Promise();
        $promise->onCancel(function () use (&$handlerCalled) {
            $handlerCalled = true;
        });

        $promise->cancel();

        expect($handlerCalled)->toBeTrue();
        expect($promise->isCancelled())->toBeTrue();
    });

    test('cancel handler is not executed when settled promise is cancelled', function () {
        $handlerCalled = false;

        $promise = new Promise(function ($resolve) {
            $resolve('value');
        });

        Loop::run(); // Let it settle

        $promise->onCancel(function () use (&$handlerCalled) {
            $handlerCalled = true;
        });

        $promise->cancel();

        expect($handlerCalled)->toBeFalse();
        expect($promise->isCancelled())->toBeFalse();
        expect($promise->isFulfilled())->toBeTrue();
    });

    test('promise resolved after wait cannot be cancelled', function () {
        $promise = delay(0.1)
            ->then(function () {
                return 'resolved value';
            })
        ;

        $result = $promise->wait();

        expect($result)->toBe('resolved value');
        expect($promise->isFulfilled())->toBeTrue();

        $promise->cancel();

        expect($promise->isCancelled())->toBeFalse();
        expect($promise->isFulfilled())->toBeTrue();
    });

    test('cancelChain does not cancel if promise is already resolved', function () {
        $cancelCalled = false;

        $promise = delay(0.1)
            ->onCancel(function () use (&$cancelCalled) {
                $cancelCalled = true;
            })
            ->then(function () {
                return 'hello world';
            })
        ;

        $promise->wait();

        expect($promise->isFulfilled())->toBeTrue();

        $promise->cancelChain();

        expect($cancelCalled)->toBeFalse();
        expect($promise->isCancelled())->toBeFalse();
        expect($promise->isFulfilled())->toBeTrue();
    });

    test('cancelChain cancels pending promise chain', function () {
        $parentCancelled = false;
        $childCancelled = false;

        $parent = delay(5)
            ->onCancel(function () use (&$parentCancelled) {
                $parentCancelled = true;
            })
        ;

        $child = $parent->then(function () {
            return 'should not execute';
        })->onCancel(function () use (&$childCancelled) {
            $childCancelled = true;
        });

        $child->cancelChain();

        expect($parentCancelled)->toBeTrue();
        expect($childCancelled)->toBeTrue();
        expect($parent->isCancelled())->toBeTrue();
        expect($child->isCancelled())->toBeTrue();
    });

    test('multiple cancel calls on same promise only execute handler once', function () {
        $callCount = 0;

        $promise = new Promise();
        $promise->onCancel(function () use (&$callCount) {
            $callCount++;
        });

        $promise->cancel();
        $promise->cancel();
        $promise->cancel();

        expect($callCount)->toBe(1);
        expect($promise->isCancelled())->toBeTrue();
    });

    test('cancelled promise clears pending callbacks', function () {
        $thenCalled = false;
        $catchCalled = false;

        $promise = new Promise();

        $promise->then(function ($value) use (&$thenCalled) {
            $thenCalled = true;

            return $value;
        });

        $promise->catch(function ($reason) use (&$catchCalled) {
            $catchCalled = true;

            return $reason;
        });

        $promise->cancel();

        $promise->resolve('test');
        $promise->reject(new Exception('test'));

        Loop::run();

        expect($thenCalled)->toBeFalse();
        expect($catchCalled)->toBeFalse();
        expect($promise->isCancelled())->toBeTrue();
    });

    test('onCancel registered after cancellation executes immediately', function () {
        $handlerCalled = false;

        $promise = new Promise();
        $promise->cancel();

        $promise->onCancel(function () use (&$handlerCalled) {
            $handlerCalled = true;
        });

        expect($handlerCalled)->toBeTrue();
    });

    test('child promises are cancelled when parent is cancelled', function () {
        $parent = new Promise();
        $child1 = $parent->then(fn ($v) => $v);
        $child2 = $child1->then(fn ($v) => $v);

        expect($parent->isPending())->toBeTrue();
        expect($child1->isPending())->toBeTrue();
        expect($child2->isPending())->toBeTrue();

        $parent->cancel();

        expect($parent->isCancelled())->toBeTrue();
        expect($child1->isCancelled())->toBeTrue();
        expect($child2->isCancelled())->toBeTrue();
    });

    test('state remains immutable after settlement', function () {
        $promise = new Promise(function ($resolve) {
            $resolve('original value');
        });

        Loop::run();

        $originalValue = $promise->getValue();

        $promise->cancel();
        $promise->reject(new Exception('should not work'));
        $promise->resolve('new value');

        expect($promise->isFulfilled())->toBeTrue();
        expect($promise->isCancelled())->toBeFalse();
        expect($promise->isRejected())->toBeFalse();
        expect($promise->getValue())->toBe($originalValue);
    });

    test('synchronously resolved promise in constructor cannot be cancelled', function () {
        $promise = new Promise(function ($resolve) {
            $resolve('immediate value');
        });

        Loop::run();

        expect($promise->isFulfilled())->toBeTrue();

        $promise->cancel();

        expect($promise->isCancelled())->toBeFalse();
        expect($promise->isFulfilled())->toBeTrue();
    });

    test('promise cancelled before event loop runs stays cancelled', function () {
        $promise = new Promise();

        $promise->then(function () {
            return 'should not execute';
        });

        $promise->cancel();
        $promise->resolve('test');

        Loop::run();

        expect($promise->isCancelled())->toBeTrue();
        expect($promise->isFulfilled())->toBeFalse();
    });
});
