<?php

declare(strict_types=1);

use Hibla\Promise\CancellablePromise;

describe('CancellablePromise Constructor', function () {
    it('works with executor function', function () {
        $resolved = false;

        $promise = new CancellablePromise(function ($resolve, $reject) use (&$resolved) {
            $resolve('executor result');
            $resolved = true;
        });

        expect($resolved)->toBeTrue()
            ->and($promise->isResolved())->toBeTrue()
            ->and($promise->getValue())->toBe('executor result')
        ;
    });

    it('can be cancelled even after executor resolved it', function () {
        $promise = new CancellablePromise(function ($resolve, $reject) {
            $resolve('executor result');
        });

        expect($promise->isResolved())->toBeTrue()
            ->and($promise->getValue())->toBe('executor result')
        ;

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue()
            ->and($promise->isResolved())->toBeTrue()
            ->and($promise->isRejected())->toBeFalse()
            ->and($promise->getValue())->toBe('executor result')
        ;
    });

    it('can be cancelled before executor resolves', function () {
        $resolveCallback = null;

        $promise = new CancellablePromise(function ($resolve, $reject) use (&$resolveCallback) {
            $resolveCallback = $resolve;
        });

        $promise->cancel();

        if ($resolveCallback) {
            $resolveCallback('executor result');
        }

        expect($promise->isCancelled())->toBeTrue()
            ->and($promise->isRejected())->toBeTrue()
            ->and($promise->isResolved())->toBeFalse()
        ;
    });
});
