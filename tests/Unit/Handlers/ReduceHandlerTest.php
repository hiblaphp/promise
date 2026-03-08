<?php

declare(strict_types=1);

use function Hibla\delay;

use Hibla\Promise\Handlers\ReduceHandler;
use Hibla\Promise\Promise;

describe('ReduceHandler', function () {

    describe('reduce', function () {
        it('sums integers with a synchronous reducer', function () {
            $handler = new ReduceHandler();

            $result = $handler->reduce(
                [1, 2, 3, 4, 5],
                fn (int $carry, int $n) => $carry + $n,
                0
            )->wait();

            expect($result)->toBe(15);
        });

        it('sums integers with an async reducer', function () {
            $handler = new ReduceHandler();

            $result = $handler->reduce(
                [1, 2, 3, 4, 5],
                fn (int $carry, int $n) => Promise::resolved($carry + $n),
                0
            )->wait();

            expect($result)->toBe(15);
        });

        it('concatenates strings', function () {
            $handler = new ReduceHandler();

            $result = $handler->reduce(
                ['hello', ' ', 'world'],
                fn (string $carry, string $word) => $carry . $word,
                ''
            )->wait();

            expect($result)->toBe('hello world');
        });

        it('resolves promise items before passing to reducer', function () {
            $handler = new ReduceHandler();

            $result = $handler->reduce(
                [Promise::resolved(10), Promise::resolved(20), Promise::resolved(30)],
                fn (int $carry, int $n) => $carry + $n,
                0
            )->wait();

            expect($result)->toBe(60);
        });

        it('passes key as third argument to reducer', function () {
            $handler = new ReduceHandler();
            $capturedKeys = [];

            $handler->reduce(
                ['a' => 1, 'b' => 2, 'c' => 3],
                function (int $carry, int $n, string $key) use (&$capturedKeys) {
                    $capturedKeys[] = $key;

                    return $carry + $n;
                },
                0
            )->wait();

            expect($capturedKeys)->toBe(['a', 'b', 'c']);
        });

        it('returns initial value for empty input', function () {
            $handler = new ReduceHandler();

            $result = $handler->reduce([], fn ($carry, $n) => $carry + $n, 42)->wait();

            expect($result)->toBe(42);
        });

        it('returns initial value of null when not specified and input is empty', function () {
            $handler = new ReduceHandler();

            $result = $handler->reduce([], fn ($carry, $n) => $n)->wait();

            expect($result)->toBeNull();
        });

        it('processes a single item correctly', function () {
            $handler = new ReduceHandler();

            $result = $handler->reduce(
                [42],
                fn (int $carry, int $n) => $carry + $n,
                0
            )->wait();

            expect($result)->toBe(42);
        });

        it('executes sequentially — each step receives the previous carry', function () {
            $handler = new ReduceHandler();
            $executionOrder = [];

            $result = $handler->reduce(
                [1, 2, 3],
                function (array $carry, int $n) use (&$executionOrder) {
                    $executionOrder[] = "step_{$n}_carry_" . count($carry);
                    $carry[] = $n;

                    return Promise::resolved($carry);
                },
                []
            )->wait();

            expect($executionOrder)->toBe([
                'step_1_carry_0',
                'step_2_carry_1',
                'step_3_carry_2',
            ]);
            expect($result)->toBe([1, 2, 3]);
        });

        it('builds a lookup map from async sources', function () {
            $handler = new ReduceHandler();

            $result = $handler->reduce(
                ['alice', 'bob', 'charlie'],
                function (array $carry, string $name) {
                    return Promise::resolved(strlen($name))
                        ->then(function (int $len) use ($carry, $name) {
                            $carry[$name] = $len;

                            return $carry;
                        })
                    ;
                },
                []
            )->wait();

            expect($result)->toBe(['alice' => 5, 'bob' => 3, 'charlie' => 7]);
        });

        it('consumes a generator input', function () {
            $handler = new ReduceHandler();

            $gen = (function () {
                yield 10;
                yield 20;
                yield 30;
            })();

            $result = $handler->reduce(
                $gen,
                fn (int $carry, int $n) => $carry + $n,
                0
            )->wait();

            expect($result)->toBe(60);
        });

        it('preserves non-sequential numeric keys passed to reducer', function () {
            $handler = new ReduceHandler();
            $capturedKeys = [];

            $handler->reduce(
                [5 => 'a', 10 => 'b', 15 => 'c'],
                function (array $carry, string $val, int $key) use (&$capturedKeys) {
                    $capturedKeys[] = $key;
                    $carry[] = $val;

                    return $carry;
                },
                []
            )->wait();

            expect($capturedKeys)->toBe([5, 10, 15]);
        });

        it('rejects when reducer throws synchronously', function () {
            $handler = new ReduceHandler();

            expect(fn () => $handler->reduce(
                [1, 2, 3],
                function (int $carry, int $n) {
                    if ($n === 2) {
                        throw new RuntimeException('Reducer failed at 2');
                    }

                    return $carry + $n;
                },
                0
            )->wait())->toThrow(RuntimeException::class, 'Reducer failed at 2');
        });

        it('rejects when reducer returns a rejected promise', function () {
            $handler = new ReduceHandler();

            expect(fn () => $handler->reduce(
                [1, 2, 3],
                fn (int $carry, int $n) => $n === 2
                    ? Promise::rejected(new RuntimeException('Async reducer failure'))
                    : Promise::resolved($carry + $n),
                0
            )->wait())->toThrow(RuntimeException::class, 'Async reducer failure');
        });

        it('rejects when iterator throws synchronously', function () {
            $handler = new ReduceHandler();

            $generator = function () {
                yield 1;

                throw new RuntimeException('Iterator failure');
            };

            expect(fn () => $handler->reduce(
                $generator(),
                fn (int $carry, int $n) => $carry + $n,
                0
            )->wait())->toThrow(RuntimeException::class, 'Iterator failure');
        });

        it('stops processing after first rejection', function () {
            $handler = new ReduceHandler();
            $processed = [];

            try {
                $handler->reduce(
                    [1, 2, 3, 4, 5],
                    function (array $carry, int $n) use (&$processed) {
                        $processed[] = $n;

                        if ($n === 3) {
                            throw new RuntimeException('Stop here');
                        }

                        $carry[] = $n;

                        return $carry;
                    },
                    []
                )->wait();
            } catch (RuntimeException) {
                // expected
            }

            expect($processed)->not->toContain(4);
            expect($processed)->not->toContain(5);
        });

        it('works with mixed promise and scalar items', function () {
            $handler = new ReduceHandler();

            $result = $handler->reduce(
                [1, Promise::resolved(2), 3, Promise::resolved(4)],
                fn (int $carry, int $n) => $carry + $n,
                0
            )->wait();

            expect($result)->toBe(10);
        });

        it('accumulates into an array correctly', function () {
            $handler = new ReduceHandler();

            $result = $handler->reduce(
                ['a', 'b', 'c', 'd'],
                function (array $carry, string $val) {
                    $carry[] = strtoupper($val);

                    return $carry;
                },
                []
            )->wait();

            expect($result)->toBe(['A', 'B', 'C', 'D']);
        });

        it('works with async reducer using delay', function () {
            $handler = new ReduceHandler();

            $result = $handler->reduce(
                [1, 2, 3],
                fn (int $carry, int $n) => delay(0.01)->then(fn () => $carry + $n),
                0
            )->wait();

            expect($result)->toBe(6);
        });

        it('handles initial value of null explicitly', function () {
            $handler = new ReduceHandler();

            $result = $handler->reduce(
                [1, 2, 3],
                fn (mixed $carry, int $n) => $carry === null ? $n : $carry + $n,
            )->wait();

            expect($result)->toBe(6);
        });
    });
});
