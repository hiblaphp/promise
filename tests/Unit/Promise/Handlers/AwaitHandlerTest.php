<?php

use Hibla\EventLoop\EventLoop;
use Hibla\Promise\Handlers\AwaitHandler;
use Hibla\Promise\Interfaces\PromiseInterface;

describe('AwaitHandler', function () {
    describe('resolved promise', function () {
        it('should return value immediately for resolved promise', function () {
            $promise = Mockery::mock(PromiseInterface::class);
            $value = 'resolved value';

            $promise->shouldReceive('isResolved')->andReturn(true);
            $promise->shouldReceive('getValue')->andReturn($value);

            $result = (new AwaitHandler())->await($promise);

            expect($result)->toBe($value);
        });
    });

    describe('rejected promise', function () {
        it('should throw exception for rejected promise with throwable reason', function () {
            $promise = Mockery::mock(PromiseInterface::class);
            $reason = new Exception('test error');

            $promise->shouldReceive('isResolved')->andReturn(false);
            $promise->shouldReceive('isRejected')->andReturn(true);
            $promise->shouldReceive('getReason')->andReturn($reason);

            expect(fn () => (new AwaitHandler())->await($promise))
                ->toThrow(Exception::class, 'test error')
            ;
        });

        it('should throw wrapped exception for non-throwable reason', function () {
            $promise = Mockery::mock(PromiseInterface::class);
            $reason = 'string error';

            $promise->shouldReceive('isResolved')->andReturn(false);
            $promise->shouldReceive('isRejected')->andReturn(true);
            $promise->shouldReceive('getReason')->andReturn($reason);

            expect(fn () => (new AwaitHandler())->await($promise))
                ->toThrow(Exception::class, 'string error')
            ;
        });
    });

    describe('pending promise', function () {
        it('should wait for promise to resolve', function () {
            $promise = Mockery::mock(PromiseInterface::class);
            $value = 'async value';

            $promise->shouldReceive('isResolved')->andReturn(false);
            $promise->shouldReceive('isRejected')->andReturn(false);

            $promise->shouldReceive('then')
                ->andReturnUsing(function ($onFulfilled) use ($promise, $value) {
                    $onFulfilled($value);

                    return $promise;
                })
            ;

            $promise->shouldReceive('catch')
                ->andReturn($promise)
            ;

            $result = (new AwaitHandler())->await($promise);

            expect($result)->toBe($value);
        });

        it('should handle promise rejection during wait', function () {
            $promise = Mockery::mock(PromiseInterface::class);
            $reason = new Exception('async error');

            $promise->shouldReceive('isResolved')->andReturn(false);
            $promise->shouldReceive('isRejected')->andReturn(false);

            $promise->shouldReceive('then')
                ->andReturn($promise)
            ;

            $promise->shouldReceive('catch')
                ->andReturnUsing(function ($onRejected) use ($reason) {
                    $onRejected($reason);

                    return Mockery::mock(PromiseInterface::class);
                })
            ;

            expect(fn () => (new AwaitHandler())->await($promise))
                ->toThrow(Exception::class, 'async error')
            ;
        });
    });

    describe('event loop reset', function () {
        it('should reset event loop by default', function () {
            $promise = Mockery::mock(PromiseInterface::class);

            $promise->shouldReceive('isResolved')->andReturn(true);
            $promise->shouldReceive('getValue')->andReturn('value');

            $initialInstance = EventLoop::getInstance();
            expect($initialInstance)->toBeInstanceOf(EventLoop::class);

            (new AwaitHandler())->await($promise);

            $newInstance = EventLoop::getInstance();
            expect($newInstance)->not->toBe($initialInstance);
        });

        it('should not reset event loop when disabled', function () {
            $promise = Mockery::mock(PromiseInterface::class);

            $promise->shouldReceive('isResolved')->andReturn(true);
            $promise->shouldReceive('getValue')->andReturn('value');

            $initialInstance = EventLoop::getInstance();
            expect($initialInstance)->toBeInstanceOf(EventLoop::class);

            (new AwaitHandler())->await($promise, false);

            $currentInstance = EventLoop::getInstance();
            expect($currentInstance)->toBe($initialInstance);
        });
    });

    describe('safe string cast', function () {
        it('should handle different value types for error messages', function () {
            $promise = Mockery::mock(PromiseInterface::class);

            $promise->shouldReceive('isResolved')->andReturn(false);
            $promise->shouldReceive('isRejected')->andReturn(true);
            $promise->shouldReceive('getReason')->andReturn(['key' => 'value']);

            expect(fn () => (new AwaitHandler())->await($promise))
                ->toThrow(Exception::class)
                ->and(fn () => (new AwaitHandler())->await($promise))
                ->toThrow(fn (Exception $e) => str_contains($e->getMessage(), 'Array:'))
            ;
        });

        it('should handle object with toString', function () {
            $promise = Mockery::mock(PromiseInterface::class);
            $reason = new class () {
                public function __toString(): string
                {
                    return 'custom error';
                }
            };

            $promise->shouldReceive('isResolved')->andReturn(false);
            $promise->shouldReceive('isRejected')->andReturn(true);
            $promise->shouldReceive('getReason')->andReturn($reason);

            expect(fn () => (new AwaitHandler())->await($promise))
                ->toThrow(Exception::class, 'custom error')
            ;
        });
    });
});

afterEach(function () {
    Mockery::close();
});
