<?php

declare(strict_types=1);

use Hibla\Promise\Handlers\ConcurrencyHandler;
use Hibla\Promise\Promise;

describe('ConcurrencyHandler map() concurrency', function () {
    it('processes all items when no concurrency argument is given', function () {
        $handler = new ConcurrencyHandler();
        $items = range(1, 25);

        $result = $handler->map($items, fn ($item) => Promise::resolved($item * 2))->wait();

        expect($result)->toHaveCount(25)
            ->and($result[0])->toBe(2)
            ->and($result[24])->toBe(50)
        ;
    });

    it('processes all items when null is passed as concurrency', function () {
        $handler = new ConcurrencyHandler();
        $items = ['a' => 1, 'b' => 2, 'c' => 3];

        $result = $handler->map($items, fn ($v) => Promise::resolved($v + 10), null)->wait();

        expect($result)->toBe(['a' => 11, 'b' => 12, 'c' => 13]);
    });

    it('respects an explicit concurrency limit when given', function () {
        $handler = new ConcurrencyHandler();
        $items = range(1, 5);

        $result = $handler->map($items, fn ($v) => Promise::resolved($v * 3), 2)->wait();

        expect($result)->toBe([3, 6, 9, 12, 15]);
    });
});
