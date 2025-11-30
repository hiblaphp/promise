<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

expect()->extend('toBePromise', function () {
    return $this->toBeInstanceOf(PromiseInterface::class);
});

expect()->extend('toBeCancellablePromise', function () {
    return $this->toBeInstanceOf(CancellablePromiseInterface::class);
});

function delayedValue($value, $delayMs)
{
    return new Promise(function ($resolve) use ($value, $delayMs) {
        Loop::addTimer($delayMs / 1000, function () use ($resolve, $value) {
            $resolve($value);
        });
    });
}

function delayedReject($error, $delayMs)
{
    return new Promise(function ($resolve, $reject) use ($error, $delayMs) {
        Loop::addTimer($delayMs / 1000, function () use ($reject, $error) {
            $reject($error);
        });
    });
}
