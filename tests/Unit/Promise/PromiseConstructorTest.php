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
            ->and($promise->getValue())->toBe('test value')
        ;
    });

    it('handles executor that rejects immediately', function () {
        $exception = new Exception('test error');
        $promise = new Promise(function ($resolve, $reject) use ($exception) {
            $reject($exception);
        });

        expect($promise->isRejected())->toBeTrue()
            ->and($promise->getReason())->toBe($exception)
        ;
    });

    it('handles executor that throws an exception', function () {
        $exception = new Exception('executor error');

        $promise = new Promise(function ($resolve, $reject) use ($exception) {
            throw $exception;
        });

        expect($promise->isRejected())->toBeTrue()
            ->and($promise->getReason())->toBe($exception)
        ;
    });
});
