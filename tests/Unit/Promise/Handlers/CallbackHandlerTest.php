<?php

describe('CallbackHandler', function () {
    describe('then callbacks', function () {
        it('should execute then callbacks with value', function () {
            $handler = callbackHandler();
            $executedCallbacks = [];
            $value = 'test value';

            $handler->addThenCallback(function ($v) use (&$executedCallbacks) {
                $executedCallbacks[] = "callback1:$v";
            });

            $handler->addThenCallback(function ($v) use (&$executedCallbacks) {
                $executedCallbacks[] = "callback2:$v";
            });

            $handler->executeThenCallbacks($value);

            expect($executedCallbacks)->toBe([
                "callback1:$value",
                "callback2:$value",
            ]);
        });

        it('should execute all callbacks in order', function () {
            $handler = callbackHandler();
            $order = [];

            $handler->addThenCallback(function () use (&$order) {
                $order[] = 1;
            });

            $handler->addThenCallback(function () use (&$order) {
                $order[] = 2;
            });

            $handler->addThenCallback(function () use (&$order) {
                $order[] = 3;
            });

            $handler->executeThenCallbacks('value');

            expect($order)->toBe([1, 2, 3]);
        });

        it('should propagate exceptions from callbacks', function () {
            $handler = callbackHandler();

            $handler->addThenCallback(function () {
                throw new Exception('callback error');
            });

            expect(fn () => $handler->executeThenCallbacks('value'))
                ->toThrow(Exception::class, 'callback error')
            ;
        });

        it('should stop execution when callback throws', function () {
            $handler = callbackHandler();
            $executedCallbacks = [];

            $handler->addThenCallback(function () {
                throw new Exception('first callback error');
            });

            $handler->addThenCallback(function ($v) use (&$executedCallbacks) {
                $executedCallbacks[] = 'should not execute';
            });

            try {
                $handler->executeThenCallbacks('value');
            } catch (Exception $e) {
                // Expected
            }

            expect($executedCallbacks)->toBe([]);
        });
    });

    describe('catch callbacks', function () {
        it('should execute catch callbacks with reason', function () {
            $handler = callbackHandler();
            $executedCallbacks = [];
            $reason = 'error reason';

            $handler->addCatchCallback(function ($r) use (&$executedCallbacks) {
                $executedCallbacks[] = "callback1:$r";
            });

            $handler->addCatchCallback(function ($r) use (&$executedCallbacks) {
                $executedCallbacks[] = "callback2:$r";
            });

            $handler->executeCatchCallbacks($reason);

            expect($executedCallbacks)->toBe([
                "callback1:$reason",
                "callback2:$reason",
            ]);
        });

        it('should execute all callbacks in order', function () {
            $handler = callbackHandler();
            $order = [];

            $handler->addCatchCallback(function () use (&$order) {
                $order[] = 1;
            });

            $handler->addCatchCallback(function () use (&$order) {
                $order[] = 2;
            });

            $handler->addCatchCallback(function () use (&$order) {
                $order[] = 3;
            });

            $handler->executeCatchCallbacks('error');

            expect($order)->toBe([1, 2, 3]);
        });

        it('should propagate exceptions from callbacks', function () {
            $handler = callbackHandler();

            $handler->addCatchCallback(function () {
                throw new Exception('catch callback error');
            });

            expect(fn () => $handler->executeCatchCallbacks('reason'))
                ->toThrow(Exception::class, 'catch callback error')
            ;
        });

        it('should stop execution when callback throws', function () {
            $handler = callbackHandler();
            $executedCallbacks = [];

            $handler->addCatchCallback(function () {
                throw new Exception('error in catch');
            });

            $handler->addCatchCallback(function ($r) use (&$executedCallbacks) {
                $executedCallbacks[] = 'should not execute';
            });

            try {
                $handler->executeCatchCallbacks('reason');
            } catch (Exception $e) {
                // Expected
            }

            expect($executedCallbacks)->toBe([]);
        });
    });

    describe('finally callbacks', function () {
        it('should execute finally callbacks without parameters', function () {
            $handler = callbackHandler();
            $executedCallbacks = [];

            $handler->addFinallyCallback(function () use (&$executedCallbacks) {
                $executedCallbacks[] = 'callback1';
            });

            $handler->addFinallyCallback(function () use (&$executedCallbacks) {
                $executedCallbacks[] = 'callback2';
            });

            $handler->executeFinallyCallbacks();

            expect($executedCallbacks)->toBe(['callback1', 'callback2']);
        });

        it('should execute all callbacks in order', function () {
            $handler = callbackHandler();
            $order = [];

            $handler->addFinallyCallback(function () use (&$order) {
                $order[] = 1;
            });

            $handler->addFinallyCallback(function () use (&$order) {
                $order[] = 2;
            });

            $handler->addFinallyCallback(function () use (&$order) {
                $order[] = 3;
            });

            $handler->executeFinallyCallbacks();

            expect($order)->toBe([1, 2, 3]);
        });

        it('should propagate exceptions from callbacks', function () {
            $handler = callbackHandler();

            $handler->addFinallyCallback(function () {
                throw new Exception('finally callback error');
            });

            expect(fn () => $handler->executeFinallyCallbacks())
                ->toThrow(Exception::class, 'finally callback error')
            ;
        });

        it('should stop execution when callback throws', function () {
            $handler = callbackHandler();
            $executedCallbacks = [];

            $handler->addFinallyCallback(function () {
                throw new Exception('error in finally');
            });

            $handler->addFinallyCallback(function () use (&$executedCallbacks) {
                $executedCallbacks[] = 'should not execute';
            });

            try {
                $handler->executeFinallyCallbacks();
            } catch (Exception $e) {
                // Expected
            }

            expect($executedCallbacks)->toBe([]);
        });
    });

    describe('multiple callback types', function () {
        it('should handle different callback types independently', function () {
            $handler = callbackHandler();
            $results = [];

            $handler->addThenCallback(function ($v) use (&$results) {
                $results['then'] = $v;
            });

            $handler->addCatchCallback(function ($r) use (&$results) {
                $results['catch'] = $r;
            });

            $handler->addFinallyCallback(function () use (&$results) {
                $results['finally'] = true;
            });

            // Execute only then and finally
            $handler->executeThenCallbacks('value');
            $handler->executeFinallyCallbacks();

            expect($results)->toBe([
                'then' => 'value',
                'finally' => true,
            ]);
        });
    });
});
