<?php

declare(strict_types=1);

use Hibla\Promise\Handlers\ConcurrencyHandler;
use Hibla\Promise\Promise;

describe('ConcurrencyHandler', function () {
    it('runs tasks concurrently', function () {
        $handler = new ConcurrencyHandler();
        $tasks = [
            fn () => Promise::resolved('result1'),
            fn () => Promise::resolved('result2'),
            fn () => Promise::resolved('result3'),
        ];

        $results = $handler->concurrent($tasks, 2)->await();

        expect($results)->toBe(['result1', 'result2', 'result3']);
    });

    it('respects concurrency limit', function () {
        $handler = new ConcurrencyHandler();
        $counter = 0;
        $maxConcurrent = 0;

        $tasks = array_fill(0, 5, function () use (&$counter, &$maxConcurrent) {
            $counter++;
            $maxConcurrent = max($maxConcurrent, $counter);

            return new Promise(function ($resolve) use (&$counter) {
                usleep(10000);
                $counter--;
                $resolve('done');
            });
        });

        $handler->concurrent($tasks, 2)->await();

        expect($maxConcurrent)->toBeLessThanOrEqual(2);
    });

    it('handles empty task array', function () {
        $handler = new ConcurrencyHandler();
        $results = $handler->concurrent([])->await();

        expect($results)->toBe([]);
    });

    it('preserves array keys', function () {
        $handler = new ConcurrencyHandler();
        $tasks = [
            'task1' => fn () => Promise::resolved('result1'),
            'task2' => fn () => Promise::resolved('result2'),
        ];

        $results = $handler->concurrent($tasks)->await();

        expect($results)->toBe([
            'task1' => 'result1',
            'task2' => 'result2',
        ]);
    });

    it('handles task exceptions', function () {
        $handler = new ConcurrencyHandler();
        $tasks = [
            fn () => Promise::resolved('success'),
            fn () => Promise::rejected(new Exception('task failed')),
        ];

        expect(fn () => $handler->concurrent($tasks)->await())
            ->toThrow(Exception::class, 'task failed')
        ;
    });

    it('runs batch processing', function () {
        $handler = new ConcurrencyHandler();
        $tasks = array_fill(0, 5, fn () => Promise::resolved('result'));

        $results = $handler->batch($tasks, 2)->await();

        expect($results)->toHaveCount(5);
        expect(array_unique($results))->toBe(['result']);
    });

    it('handles concurrent settled operations', function () {
        $handler = new ConcurrencyHandler();
        $tasks = [
            fn () => Promise::resolved('success'),
            fn () => Promise::rejected(new Exception('failure')),
            fn () => Promise::resolved('another success'),
        ];

        $results = $handler->concurrentSettled($tasks)->await();

        expect($results)->toHaveCount(3);
        expect($results[0]['status'])->toBe('fulfilled');
        expect($results[0]['value'])->toBe('success');
        expect($results[1]['status'])->toBe('rejected');
        expect($results[2]['status'])->toBe('fulfilled');
    });

    it('validates concurrency parameter', function () {
        $handler = new ConcurrencyHandler();

        expect(fn () => $handler->concurrent([], 0)->await())
            ->toThrow(InvalidArgumentException::class, 'Concurrency limit must be greater than 0')
        ;

        expect(fn () => $handler->concurrent([], -1)->await())
            ->toThrow(InvalidArgumentException::class, 'Concurrency limit must be greater than 0')
        ;
    });
});
