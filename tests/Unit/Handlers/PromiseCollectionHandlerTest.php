<?php

declare(strict_types=1);

use Hibla\Promise\Exceptions\AggregateErrorException;
use Hibla\Promise\Exceptions\TimeoutException;
use Hibla\Promise\Handlers\PromiseCollectionHandler;
use Hibla\Promise\Promise;

describe('PromiseCollectionHandler', function () {
    it('resolves all promises', function () {
        $handler = new PromiseCollectionHandler();
        $promise1 = new Promise();
        $promise1->resolve('result1');

        $promise2 = new Promise();
        $promise2->resolve('result2');

        $promises = [$promise1, $promise2];

        $results = $handler->all($promises)->wait();

        expect($results)->toBe(['result1', 'result2']);
    });

    it('rejects if any promise rejects', function () {
        $handler = new PromiseCollectionHandler();
        $promise1 = new Promise();
        $promise1->resolve('success');

        $promise2 = new Promise();
        $promise2->reject(new Exception('failure'));

        $promises = [$promise1, $promise2];

        expect(fn () => $handler->all($promises)->wait())
            ->toThrow(Exception::class, 'failure')
        ;
    });

    it('handles empty promise array', function () {
        $handler = new PromiseCollectionHandler();
        $results = $handler->all([])->wait();

        expect($results)->toBe([]);
    });

    it('settles all promises', function () {
        $handler = new PromiseCollectionHandler();
        $promise1 = new Promise();
        $promise1->resolve('success');

        $promise2 = new Promise();
        $promise2->reject(new Exception('failure'));

        $promises = [$promise1, $promise2];

        $results = $handler->allSettled($promises)->wait();

        expect($results)->toHaveCount(2);
        expect($results[0]['status'])->toBe('fulfilled');
        expect($results[0]['value'])->toBe('success');
        expect($results[1]['status'])->toBe('rejected');
        expect($results[1]['reason'])->toBeInstanceOf(Exception::class);
    });

    it('races promises', function () {
        $handler = new PromiseCollectionHandler();
        $fastPromise = new Promise();
        $fastPromise->resolve('fast');

        $slowPromise = new Promise();

        $result = $handler->race([$slowPromise, $fastPromise])->wait();

        expect($result)->toBe('fast');
    });

    it('handles timeout', function () {
        $handler = new PromiseCollectionHandler();
        $slowPromise = new Promise();

        expect(fn () => $handler->timeout($slowPromise, 0.05)->wait())
            ->toThrow(TimeoutException::class)
        ;
    });

    it('resolves any promise', function () {
        $handler = new PromiseCollectionHandler();
        $promise1 = new Promise();
        $promise1->reject(new Exception('fail1'));

        $promise2 = new Promise();
        $promise2->resolve('success');

        $promise3 = new Promise();
        $promise3->reject(new Exception('fail2'));

        $promises = [$promise1, $promise2, $promise3];

        $result = $handler->any($promises)->wait();

        expect($result)->toBe('success');
    });

    it('rejects when all promises reject', function () {
        $handler = new PromiseCollectionHandler();
        $promise1 = new Promise();
        $promise1->reject(new Exception('fail1'));

        $promise2 = new Promise();
        $promise2->reject(new Exception('fail2'));

        $promises = [$promise1, $promise2];

        expect(fn () => $handler->any($promises)->wait())
            ->toThrow(AggregateErrorException::class, 'All promises were rejected')
        ;
    });

    it('validates timeout parameter', function () {
        $handler = new PromiseCollectionHandler();
        $promise = new Promise();

        expect(fn () => $handler->timeout($promise, 0))
            ->toThrow(InvalidArgumentException::class, 'Timeout must be greater than zero')
        ;

        expect(fn () => $handler->timeout($promise, -1))
            ->toThrow(InvalidArgumentException::class, 'Timeout must be greater than zero')
        ;
    });
});
