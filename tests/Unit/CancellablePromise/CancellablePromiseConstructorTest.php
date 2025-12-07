<?php

declare(strict_types=1);

use Hibla\Promise\Promise;

describe('Promise Constructor', function () {
    it('can be cancelled even after executor resolved it', function () {
        $promise = new Promise(function ($resolve, $reject) {
            $resolve('executor result');
        });

        expect($promise->isResolved())->toBeTrue()
            ->and($promise->getValue())->toBe('executor result')
        ;

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue()
            ->and($promise->isPending())->toBeFalse()
            ->and($promise->isRejected())->toBeFalse()
            ->and($promise->isResolved())->toBeFalse()
            ->and($promise->getValue())->toBeNull()
            ->and($promise->getReason())->toBeNull()
        ;
    });

    it('can be cancelled before executor resolves', function () {
        $resolveCallback = null;

        $promise = new Promise(function ($resolve, $reject) use (&$resolveCallback) {
            $resolveCallback = $resolve;
        });

        $promise->cancel();

        if ($resolveCallback) {
            $resolveCallback('executor result');
        }

        expect($promise->isCancelled())->toBeTrue()
            ->and($promise->isPending())->toBeFalse()
            ->and($promise->isRejected())->toBeFalse()
            ->and($promise->isResolved())->toBeFalse()
            ->and($promise->getValue())->toBeNull()
            ->and($promise->getReason())->toBeNull()
        ;
    });
});
