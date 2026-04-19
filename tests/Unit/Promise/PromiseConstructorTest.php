<?php

declare(strict_types=1);

use Hibla\Promise\Promise;

describe('Promise Constructor', function () {
    it('executes provided executor immediately', function () {
        $executed = false;
        $resolveCallback = null;
        $rejectCallback = null;

        new Promise(function ($resolve, $reject) use (&$executed, &$resolveCallback, &$rejectCallback) {
            $executed = true;
            $resolveCallback = $resolve;
            $rejectCallback = $reject;
        });

        expect($executed)->toBeTrue()
            ->and($resolveCallback)->toBeCallable()
            ->and($rejectCallback)->toBeCallable()
        ;
    });

    it('handles executor that resolves immediately', function () {
        $promise = new Promise(function ($resolve, $reject) {
            $resolve('test value');
        });

        expect($promise->isFulfilled())->toBeTrue()
            ->and($promise->value)->toBe('test value')
        ;
    });

    it('handles executor that rejects immediately', function () {
        $exception = new Exception('test error');
        $promise = new Promise(function ($resolve, $reject) use ($exception) {
            $reject($exception);
        });

        expect($promise->isRejected())->toBeTrue()
            ->and($promise->reason)->toBe($exception)
        ;
    });

    it('handles executor that throws an exception', function () {
        $exception = new Exception('executor error');

        $promise = new Promise(function ($resolve, $reject) use ($exception) {
            throw $exception;
        });

        expect($promise->isRejected())->toBeTrue()
            ->and($promise->reason)->toBe($exception)
        ;
    });

    it('provides onCancel as third argument to executor', function () {
        $onCancelCallback = null;

        new Promise(function ($resolve, $reject, $onCancel) use (&$onCancelCallback) {
            $onCancelCallback = $onCancel;
        });

        expect($onCancelCallback)->toBeCallable();
    });

    it('executes onCancel handler registered in executor when cancelled', function () {
        $cancelled = false;

        $promise = new Promise(function ($resolve, $reject, $onCancel) use (&$cancelled) {
            $onCancel(function () use (&$cancelled) {
                $cancelled = true;
            });
        });

        $promise->cancel();

        expect($cancelled)->toBeTrue()
            ->and($promise->isCancelled())->toBeTrue()
        ;
    });

    it('executes multiple onCancel handlers registered in executor in FIFO order', function () {
        $order = [];

        $promise = new Promise(function ($resolve, $reject, $onCancel) use (&$order) {
            $onCancel(function () use (&$order) {
                $order[] = 1;
            });
            $onCancel(function () use (&$order) {
                $order[] = 2;
            });
            $onCancel(function () use (&$order) {
                $order[] = 3;
            });
        });

        $promise->cancel();

        expect($order)->toBe([1, 2, 3]);
    });

    it('executes constructor onCancel handlers before externally registered ones', function () {
        $order = [];

        $promise = new Promise(function ($resolve, $reject, $onCancel) use (&$order) {
            $onCancel(function () use (&$order) {
                $order[] = 'constructor-1';
            });
            $onCancel(function () use (&$order) {
                $order[] = 'constructor-2';
            });
        });

        $promise->onCancel(function () use (&$order) {
            $order[] = 'external-1';
        });
        $promise->onCancel(function () use (&$order) {
            $order[] = 'external-2';
        });

        $promise->cancel();

        expect($order)->toBe(['constructor-1', 'constructor-2', 'external-1', 'external-2']);
    });

    it('does not execute onCancel handler registered in executor when promise resolves', function () {
        $cancelled = false;

        $promise = new Promise(function ($resolve, $reject, $onCancel) use (&$cancelled) {
            $onCancel(function () use (&$cancelled) {
                $cancelled = true;
            });
            $resolve('done');
        });

        expect($cancelled)->toBeFalse()
            ->and($promise->isFulfilled())->toBeTrue()
        ;
    });

    it('does not execute onCancel handler registered in executor when promise rejects', function () {
        $cancelled = false;

        $promise = new Promise(function ($resolve, $reject, $onCancel) use (&$cancelled) {
            $onCancel(function () use (&$cancelled) {
                $cancelled = true;
            });
            $reject(new Exception('error'));
        });

        $promise->reason;

        expect($cancelled)->toBeFalse()
            ->and($promise->isRejected())->toBeTrue()
        ;
    });

    it('executes onCancel handler immediately if registered after cancellation', function () {
        $cancelled = false;

        $promise = new Promise(function ($resolve, $reject, $onCancel) use (&$cancelled) {
            // simulate: cancel happens before onCancel is registered
        });

        $promise->cancel();

        // registering after cancellation should fire immediately (existing onCancel() behaviour)
        $promise->onCancel(function () use (&$cancelled) {
            $cancelled = true;
        });

        expect($cancelled)->toBeTrue();
    });
});
