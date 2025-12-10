<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Promise\Promise;

it('executes the callback when the promise is fulfilled', function () {
    $executed = 0;

    $promise = new Promise(function ($resolve) {
        $resolve('success');
    });

    $promise->finally(function () use (&$executed) {
        $executed++;
    });

    Loop::run();

    expect($executed)->toBe(1);
});

it('executes the callback when the promise is rejected', function () {
    $executed = 0;

    $promise = new Promise(function ($resolve, $reject) {
        $reject(new Exception('failure'));
    });

    $promise
        ->finally(function () use (&$executed) {
            $executed++;
        })
        ->catch(function () {
            // Catching the error to prevent "Unhandled Rejection" log output during test
        })
    ;

    Loop::run();

    expect($executed)->toBe(1);
});

it('executes the callback when the promise is cancelled', function () {
    $executed = 0;

    $promise = new Promise(function () {
        // Pending forever
    });

    $promise->finally(function () use (&$executed) {
        $executed++;
    });

    $promise->cancel();

    Loop::run();

    expect($executed)->toBe(1);
});

it('passes the resolution value through the chain', function () {
    $value = null;

    $promise = new Promise(fn ($r) => $r('original value'));

    $promise
        ->finally(function () {
            // Side effect
        })
        ->then(function ($v) use (&$value) {
            $value = $v;
        })
    ;

    Loop::run();

    expect($value)->toBe('original value');
});

it('passes the rejection reason through the chain', function () {
    $reason = null;

    $promise = new Promise(fn ($r, $j) => $j(new Exception('original error')));

    $promise
        ->finally(function () {
            // Side effect
        })
        ->catch(function ($e) use (&$reason) {
            $reason = $e;
        })
    ;

    Loop::run();

    expect($reason)->toBeInstanceOf(Exception::class)
        ->and($reason->getMessage())->toBe('original error')
    ;
});

it('does not double execute if cancellation happens after resolution', function () {
    $executed = 0;

    $promise = new Promise(function ($resolve) {
        $resolve('done');
    });

    $promise->finally(function () use (&$executed) {
        $executed++;
    });

    Loop::run();

    $promise->cancel();

    expect($executed)->toBe(1);
});
