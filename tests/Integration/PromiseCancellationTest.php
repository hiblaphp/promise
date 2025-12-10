<?php

declare(strict_types=1);

use function Hibla\delay;
use function Hibla\Promise\concurrent;

use Hibla\Promise\Interfaces\PromiseInterface;

use function Hibla\Promise\timeout;

describe('CancellablePromise Integration', function () {
    it('works with delay function', function () {
        $promise = delay(0.1);

        expect($promise)->toBeInstanceOf(PromiseInterface::class);

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
    });

    it('can create timeout operations', function () {
        $promise = timeout(delay(0.1), 0.1);

        expect($promise)->toBeInstanceOf(PromiseInterface::class);

        if ($promise instanceof PromiseInterface) {
            $promise->cancel();
            expect($promise->isCancelled())->toBeTrue();
        }
    });

    it('works with concurrent operations', function () {
        $tasks = [
            fn () => delay(0.1),
            fn () => delay(0.2),
            fn () => delay(0.3),
        ];

        $promise = concurrent($tasks, 2);

        expect($promise)->toBeInstanceOf(PromiseInterface::class);

        if ($promise instanceof PromiseInterface) {
            $promise->cancel();
            expect($promise->isCancelled())->toBeTrue();
        }
    });
});
