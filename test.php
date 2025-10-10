<?php

use Hibla\EventLoop\Loop;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

require 'vendor/autoload.php';

function cancellablePromise(): CancellablePromiseInterface
{
    $promise = new CancellablePromise();
    
    $timerId = Loop::addTimer(4.0, function () use ($promise) {
        $promise->resolve('Delayed result');
    });

    $promise->setCancelHandler(function () use ($timerId) {
        Loop::cancelTimer($timerId);
    });

    return $promise;
}

function wrapCancellablePromise(): PromiseInterface
{
    return cancellablePromise();
}

Loop::addTimer(2.0, function (){
    $promise = wrapCancellablePromise()->getRootCancellable();
    $promise->cancel();
});


wrapCancellablePromise()->then(function ($value) {
    echo "Resolved: $value\n";
});


