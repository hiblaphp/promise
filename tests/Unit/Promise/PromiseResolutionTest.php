<?php

declare(strict_types=1);

use Hibla\Promise\Promise;

describe('Promise Resolution', function () {
    it('recursively unwraps a promise resolving to another promise', function () {
        $inner = new Promise(function ($resolve) {
            $resolve('Success!');
        });

        $outer = new Promise(function ($resolve) use ($inner) {
            $resolve($inner);
        });

        expect($outer->wait())->toBe('Success!');
    });

    it('unwraps deeply nested promises (3 levels)', function () {
        $deep = new Promise(fn ($r) => $r('Level 3'));
        $middle = new Promise(fn ($r) => $r($deep));
        $top = new Promise(fn ($r) => $r($middle));

        expect($top->wait())->toBe('Level 3');
    });

    it('throws TypeError when a promise resolves to itself', function () {
        $promise = new Promise();

        $promise->resolve($promise);

        $promise->wait();
    })->throws(TypeError::class, 'Chaining cycle detected');

    it('resolves a custom object with a then method (Duck Typing)', function () {
        $thenable = new class () {
            public function then(callable $onFulfilled, callable $onRejected)
            {
                // Simulate async work
                $onFulfilled('I am a duck!');
            }
        };

        $promise = new Promise(function ($resolve) use ($thenable) {
            $resolve($thenable);
        });

        expect($promise->wait())->toBe('I am a duck!');
    });

    it('rejects when a custom thenable calls the reject callback', function () {
        $thenable = new class () {
            public function then(callable $onFulfilled, callable $onRejected)
            {
                $onRejected(new Exception('Duck exploded'));
            }
        };

        $promise = new Promise(function ($resolve) use ($thenable) {
            $resolve($thenable);
        });

        $promise->wait();
    })->throws(Exception::class, 'Duck exploded');

    it('handles thenables that resolve to another thenable (Recursive Thenables)', function () {
        $innerThenable = new class () {
            public function then($resolve)
            {
                $resolve('Deep Duck');
            }
        };

        $outerThenable = new class () {
            public $inner;

            public function then($resolve)
            {
                $resolve($this->inner);
            }
        };
        $outerThenable->inner = $innerThenable;

        $promise = new Promise(function ($resolve) use ($outerThenable) {
            $resolve($outerThenable);
        });

        expect($promise->wait())->toBe('Deep Duck');
    });
});
