<?php

describe('ResolutionHandler', function () {
    describe('resolve handling', function () {
        it('should resolve state and execute then callbacks', function () {
            $state = stateHandler();
            $callback = callbackHandler();
            $handler = resolutionHandler($state, $callback);

            $executedCallbacks = [];
            $value = 'test value';

            $callback->addThenCallback(function ($v) use (&$executedCallbacks) {
                $executedCallbacks[] = "then:$v";
            });

            $callback->addFinallyCallback(function () use (&$executedCallbacks) {
                $executedCallbacks[] = 'finally';
            });

            $handler->handleResolve($value);

            expect($state->isResolved())->toBeTrue()
                ->and($state->getValue())->toBe($value)
            ;
        });

        it('should not execute catch callbacks on resolve', function () {
            $state = stateHandler();
            $callback = callbackHandler();
            $handler = resolutionHandler($state, $callback);

            $executedCallbacks = [];

            $callback->addCatchCallback(function () use (&$executedCallbacks) {
                $executedCallbacks[] = 'catch';
            });

            $handler->handleResolve('value');

            expect($executedCallbacks)->toBeEmpty();
        });
    });

    describe('reject handling', function () {
        it('should reject state and execute catch callbacks', function () {
            $state = stateHandler();
            $callback = callbackHandler();
            $handler = resolutionHandler($state, $callback);

            $executedCallbacks = [];
            $reason = 'error reason';

            $callback->addCatchCallback(function ($r) use (&$executedCallbacks) {
                $executedCallbacks[] = "catch:$r";
            });

            $callback->addFinallyCallback(function () use (&$executedCallbacks) {
                $executedCallbacks[] = 'finally';
            });

            $handler->handleReject($reason);

            expect($state->isRejected())->toBeTrue();

            // Note: The callbacks are scheduled for next tick
        });

        it('should not execute then callbacks on reject', function () {
            $state = stateHandler();
            $callback = callbackHandler();
            $handler = resolutionHandler($state, $callback);

            $executedCallbacks = [];

            $callback->addThenCallback(function () use (&$executedCallbacks) {
                $executedCallbacks[] = 'then';
            });

            $handler->handleReject('error');

            expect($executedCallbacks)->toBeEmpty();
        });
    });

    describe('state consistency', function () {
        it('should maintain state consistency during resolution', function () {
            $state = stateHandler();
            $callback = callbackHandler();
            $handler = resolutionHandler($state, $callback);

            $value = 'test';

            expect($state->isPending())->toBeTrue();

            $handler->handleResolve($value);

            expect($state->isResolved())->toBeTrue()
                ->and($state->isPending())->toBeFalse()
                ->and($state->isRejected())->toBeFalse()
                ->and($state->getValue())->toBe($value)
            ;
        });

        it('should maintain state consistency during rejection', function () {
            $state = stateHandler();
            $callback = callbackHandler();
            $handler = resolutionHandler($state, $callback);

            $reason = 'error';

            expect($state->isPending())->toBeTrue();

            $handler->handleReject($reason);

            expect($state->isRejected())->toBeTrue()
                ->and($state->isPending())->toBeFalse()
                ->and($state->isResolved())->toBeFalse()
            ;
        });
    });
});
