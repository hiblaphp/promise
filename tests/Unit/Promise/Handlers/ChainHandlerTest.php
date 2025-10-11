<?php

use Hibla\EventLoop\Loop;
use Hibla\Promise\Interfaces\PromiseInterface;

describe('ChainHandler', function () {
    describe('then handler creation', function () {
        it('should create handler that transforms value', function () {
            $handler = chainHandler();
            $resolvedValue = null;
            $rejectedReason = null;

            $resolve = function ($value) use (&$resolvedValue) {
                $resolvedValue = $value;
            };

            $reject = function ($reason) use (&$rejectedReason) {
                $rejectedReason = $reason;
            };

            $onFulfilled = fn ($value) => strtoupper($value);

            $thenHandler = $handler->createThenHandler($onFulfilled, $resolve, $reject);
            $thenHandler('test');

            expect($resolvedValue)->toBe('TEST')
                ->and($rejectedReason)->toBeNull()
            ;
        });

        it('should create handler that passes through value when no callback', function () {
            $handler = chainHandler();
            $resolvedValue = null;

            $resolve = function ($value) use (&$resolvedValue) {
                $resolvedValue = $value;
            };

            $reject = function () {};

            $thenHandler = $handler->createThenHandler(null, $resolve, $reject);
            $thenHandler('test');

            expect($resolvedValue)->toBe('test');
        });

        it('should handle promise returned from callback', function () {
            $handler = chainHandler();
            $finalValue = null;
            $mockPromise = Mockery::mock(PromiseInterface::class);

            $resolve = function ($value) use (&$finalValue) {
                $finalValue = $value;
            };

            $reject = function () {};

            $onFulfilled = fn ($value) => $mockPromise;

            $mockPromise->shouldReceive('then')
                ->once()
                ->with($resolve, $reject)
            ;

            $thenHandler = $handler->createThenHandler($onFulfilled, $resolve, $reject);
            $thenHandler('test');

            // The mock expectation validates the behavior
        });

        it('should reject when callback throws exception', function () {
            $handler = chainHandler();
            $resolvedValue = null;
            $rejectedReason = null;

            $resolve = function ($value) use (&$resolvedValue) {
                $resolvedValue = $value;
            };

            $reject = function ($reason) use (&$rejectedReason) {
                $rejectedReason = $reason;
            };

            $onFulfilled = function () {
                throw new Exception('callback error');
            };

            $thenHandler = $handler->createThenHandler($onFulfilled, $resolve, $reject);
            $thenHandler('test');

            expect($rejectedReason)->toBeInstanceOf(Exception::class)
                ->and($rejectedReason->getMessage())->toBe('callback error')
                ->and($resolvedValue)->toBeNull()
            ;
        });
    });

    describe('catch handler creation', function () {
        it('should create handler that transforms rejection reason', function () {
            $handler = chainHandler();
            $resolvedValue = null;
            $rejectedReason = null;

            $resolve = function ($value) use (&$resolvedValue) {
                $resolvedValue = $value;
            };

            $reject = function ($reason) use (&$rejectedReason) {
                $rejectedReason = $reason;
            };

            $onRejected = fn ($reason) => "handled: $reason";

            $catchHandler = $handler->createCatchHandler($onRejected, $resolve, $reject);
            $catchHandler('error');

            expect($resolvedValue)->toBe('handled: error')
                ->and($rejectedReason)->toBeNull()
            ;
        });

        it('should create handler that passes through rejection when no callback', function () {
            $handler = chainHandler();
            $rejectedReason = null;

            $resolve = function () {};

            $reject = function ($reason) use (&$rejectedReason) {
                $rejectedReason = $reason;
            };

            $catchHandler = $handler->createCatchHandler(null, $resolve, $reject);
            $catchHandler('error');

            expect($rejectedReason)->toBe('error');
        });

        it('should handle promise returned from catch callback', function () {
            $handler = chainHandler();
            $mockPromise = Mockery::mock(PromiseInterface::class);

            $resolve = function () {};
            $reject = function () {};

            $onRejected = fn ($reason) => $mockPromise;

            $mockPromise->shouldReceive('then')
                ->once()
                ->with($resolve, $reject)
            ;

            $catchHandler = $handler->createCatchHandler($onRejected, $resolve, $reject);
            $catchHandler('error');
        });

        it('should reject when catch callback throws exception', function () {
            $handler = chainHandler();
            $rejectedReason = null;

            $resolve = function () {};

            $reject = function ($reason) use (&$rejectedReason) {
                $rejectedReason = $reason;
            };

            $onRejected = function () {
                throw new Exception('catch callback error');
            };

            $catchHandler = $handler->createCatchHandler($onRejected, $resolve, $reject);
            $catchHandler('original error');

            expect($rejectedReason)->toBeInstanceOf(Exception::class)
                ->and($rejectedReason->getMessage())->toBe('catch callback error')
            ;
        });
    });

    describe('handler scheduling', function () {
        it('should schedule handler execution', function () {
            $handler = chainHandler();
            $executed = false;

            $scheduledHandler = function () use (&$executed) {
                $executed = true;
            };

            $handler->scheduleHandler($scheduledHandler);

            Loop::run();

            expect($executed)->toBeTrue();
        });
    });
});

afterEach(function () {
    Mockery::close();
});
