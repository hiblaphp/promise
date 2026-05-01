<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\AggregateErrorException;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

describe('Promise finally() optimization', function () {
    it('passes the resolved value through when onFinally returns void', function () {
        $promise = Promise::resolved('original value');
        $finallyCalled = false;

        $result = $promise
            ->finally(function () use (&$finallyCalled): void {
                $finallyCalled = true;
            })
            ->wait()
        ;

        expect($result)->toBe('original value')
            ->and($finallyCalled)->toBeTrue()
        ;
    });

    it('re-rejects with the original reason when onFinally returns void', function () {
        $exception = new RuntimeException('original error');
        $promise = Promise::rejected($exception);
        $finallyCalled = false;

        expect(function () use ($promise, &$finallyCalled) {
            $promise
                ->finally(function () use (&$finallyCalled): void {
                    $finallyCalled = true;
                })
                ->wait()
            ;
        })->toThrow(RuntimeException::class, 'original error');

        expect($finallyCalled)->toBeTrue();
    });

    it('waits for the returned promise before passing through original value', function () {
        $sideEffect = false;

        $promise = Promise::resolved('value')
            ->finally(function () use (&$sideEffect): PromiseInterface {
                return new Promise(function ($resolve) use (&$sideEffect): void {
                    Loop::addTimer(0.001, function () use ($resolve, &$sideEffect): void {
                        $sideEffect = true;
                        $resolve(null);
                    });
                });
            })
        ;

        $result = $promise->wait();

        expect($result)->toBe('value')
            ->and($sideEffect)->toBeTrue()
        ;
    });

    it('rejects when the returned promise from onFinally rejects', function () {
        $promise = Promise::resolved('value')
            ->finally(function (): PromiseInterface {
                return Promise::rejected(new RuntimeException('finally error'));
            })
        ;

        expect(fn () => $promise->wait())
            ->toThrow(RuntimeException::class, 'finally error')
        ;
    });
});

describe('Promise cancel() with multiple child exceptions', function () {
    it('throws AggregateErrorException when multiple cancel handlers throw', function () {
        $promise = new Promise();
        $promise->onCancel(function (): void {
            throw new RuntimeException('handler 1 error');
        });
        $promise->onCancel(function (): void {
            throw new RuntimeException('handler 2 error');
        });

        expect(fn () => $promise->cancel())
            ->toThrow(AggregateErrorException::class)
        ;
    });

    it('throws the single exception directly when only one cancel handler throws', function () {
        $promise = new Promise();
        $promise->onCancel(function (): void {
            throw new RuntimeException('single handler error');
        });

        expect(fn () => $promise->cancel())
            ->toThrow(RuntimeException::class, 'single handler error')
        ;
    });
});
