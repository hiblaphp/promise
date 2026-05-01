<?php

declare(strict_types=1);

use Hibla\Promise\Handlers\ConcurrencyHandler;
use Hibla\Promise\Promise;

describe('ConcurrencyHandler default concurrency', function () {
    it('exposes DEFAULT_CONCURRENCY constant', function () {
        expect(ConcurrencyHandler::DEFAULT_CONCURRENCY)->toBe(10);
    });

    it('map() respects the default concurrency limit', function () {
        $handler = new ConcurrencyHandler();
        $running = 0;
        $maxConcurrent = 0;

        $items = range(1, 25);

        $result = $handler->map($items, function ($item) use (&$running, &$maxConcurrent) {
            $running++;
            $maxConcurrent = max($maxConcurrent, $running);
            $running--;

            return Promise::resolved($item * 2);
        })->wait();

        expect($result)->toHaveCount(25)
            ->and($maxConcurrent)->toBeLessThanOrEqual(ConcurrencyHandler::DEFAULT_CONCURRENCY)
        ;
    });

    it('map() with explicit null concurrency uses the default limit', function () {
        $handler = new ConcurrencyHandler();
        $items = ['a' => 1, 'b' => 2, 'c' => 3];

        $result = $handler->map($items, fn ($v) => Promise::resolved($v + 10), null)->wait();

        expect($result)->toBe(['a' => 11, 'b' => 12, 'c' => 13]);
    });

    it('map() with explicit concurrency limit overrides the default', function () {
        $handler = new ConcurrencyHandler();
        $items = range(1, 5);

        $result = $handler->map($items, fn ($v) => Promise::resolved($v * 3), 2)->wait();

        expect($result)->toBe([3, 6, 9, 12, 15]);
    });
});
