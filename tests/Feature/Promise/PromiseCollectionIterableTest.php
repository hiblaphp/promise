<?php

declare(strict_types=1);

use Hibla\Promise\Exceptions\AggregateErrorException;
use Hibla\Promise\Exceptions\TimeoutException;
use Hibla\Promise\Handlers\PromiseCollectionHandler;
use Hibla\Promise\Promise;

describe('Promise Collection Handler - Iterable Support', function () {

    describe('all() method', function () {
        it('resolves with array when given array', function () {
            $handler = new PromiseCollectionHandler();
            $promises = [
                'a' => delayedValue('value_a', 10),
                'b' => delayedValue('value_b', 15),
                'c' => delayedValue('value_c', 5),
            ];

            $result = $handler->all($promises)->wait();

            expect($result)->toBeArray()
                ->toHaveKey('a')
                ->toHaveKey('b')
                ->toHaveKey('c')
            ;
            expect($result['a'])->toBe('value_a');
            expect($result['b'])->toBe('value_b');
            expect($result['c'])->toBe('value_c');
            expect(array_keys($result))->toBe(['a', 'b', 'c']);
        });

        it('resolves with array when given generator', function () {
            $handler = new PromiseCollectionHandler();
            $generator = (function () {
                yield 'x' => delayedValue('value_x', 10);
                yield 'y' => delayedValue('value_y', 10);
                yield 'z' => delayedValue('value_z', 10);
            })();

            $result = $handler->all($generator)->wait();

            expect($result)->toBeArray();
            expect($result['x'])->toBe('value_x');
            expect($result['y'])->toBe('value_y');
            expect($result['z'])->toBe('value_z');
            expect(array_keys($result))->toBe(['x', 'y', 'z']);
        });

        it('preserves numeric keys from generator', function () {
            $handler = new PromiseCollectionHandler();
            $generator = (function () {
                yield 10 => delayedValue('tenth', 10);
                yield 5 => delayedValue('fifth', 10);
                yield 15 => delayedValue('fifteenth', 10);
            })();

            $result = $handler->all($generator)->wait();

            expect($result[10])->toBe('tenth');
            expect($result[5])->toBe('fifth');
            expect($result[15])->toBe('fifteenth');
            expect(array_keys($result))->toBe([10, 5, 15]);
        });

        it('handles empty generator', function () {
            $handler = new PromiseCollectionHandler();
            $generator = (function () {
                if (false) {
                    yield;
                }
            })();

            $result = $handler->all($generator)->wait();

            expect($result)->toBeArray()->toBeEmpty();
        });

        it('preserves order despite random completion times', function () {
            $handler = new PromiseCollectionHandler();
            $generator = (function () {
                yield 'a' => delayedValue('A', random_int(5, 50));
                yield 'b' => delayedValue('B', random_int(5, 50));
                yield 'c' => delayedValue('C', random_int(5, 50));
                yield 'd' => delayedValue('D', random_int(5, 50));
            })();

            $result = $handler->all($generator)->wait();

            expect(array_keys($result))->toBe(['a', 'b', 'c', 'd']);
            expect($result['a'])->toBe('A');
            expect($result['b'])->toBe('B');
            expect($result['c'])->toBe('C');
            expect($result['d'])->toBe('D');
        });
    });

    describe('allSettled() method', function () {
        it('resolves with settled results when given generator containing rejections', function () {
            $handler = new PromiseCollectionHandler();
            $generator = (function () {
                yield 'p1' => delayedValue('value1', 10);
                yield 'p2' => delayedReject(new Exception('failed'), 5);
                yield 'p3' => delayedValue('value3', 15);
            })();

            $result = $handler->allSettled($generator)->wait();

            expect($result)->toBeArray();
            expect($result['p1']->isFulfilled())->toBeTrue();
            expect($result['p1']->value)->toBe('value1');
            expect($result['p2']->isRejected())->toBeTrue();
            expect($result['p3']->isFulfilled())->toBeTrue();
            expect(array_keys($result))->toBe(['p1', 'p2', 'p3']);
        });
    });

    describe('race() method', function () {
        it('returns fastest result from generator', function () {
            $handler = new PromiseCollectionHandler();
            $generator = (function () {
                yield 'slow' => delayedValue('result1', 100);
                yield 'fast' => delayedValue('result2', 5);
                yield 'medium' => delayedValue('result3', 50);
            })();

            $result = $handler->race($generator)->wait();

            expect($result)->toBe('result2');
        });

        it('rejects when race encounters a fast rejection', function () {
            $handler = new PromiseCollectionHandler();
            $generator = (function () {
                yield 'p1' => delayedReject(new Exception('fast_error'), 5);
                yield 'p2' => delayedValue('slow_result', 100);
            })();

            expect(fn () => $handler->race($generator)->wait())
                ->toThrow(Exception::class, 'fast_error')
            ;
        });
    });

    describe('any() method', function () {
        it('returns first successful result from generator skipping early failures', function () {
            $handler = new PromiseCollectionHandler();
            $generator = (function () {
                yield 'a' => delayedReject(new Exception('error_a'), 5);
                yield 'b' => delayedReject(new Exception('error_b'), 10);
                yield 'c' => delayedValue('winner', 20);
            })();

            $result = $handler->any($generator)->wait();

            expect($result)->toBe('winner');
        });

        it('rejects with AggregateErrorException when all reject', function () {
            $handler = new PromiseCollectionHandler();
            $generator = (function () {
                yield 'p1' => delayedReject(new Exception('error1'), 5);
                yield 'p2' => delayedReject(new Exception('error2'), 5);
            })();

            expect(fn () => $handler->any($generator)->wait())
                ->toThrow(AggregateErrorException::class)
            ;
        });
    });

    describe('Edge Cases', function () {
        it('rejects on invalid items in generator', function () {
            $handler = new PromiseCollectionHandler();
            $generator = (function () {
                yield 'valid' => Promise::resolved('ok');
                yield 'invalid' => 'this is a string, not a promise';
            })();

            expect(fn () => $handler->all($generator)->wait())
                ->toThrow(InvalidArgumentException::class)
            ;
        });

        it('handles complex unicode keys', function () {
            $handler = new PromiseCollectionHandler();
            $generator = (function () {
                yield '🚀' => delayedValue('rocket', 5);
                yield '🔥' => delayedValue('fire', 5);
            })();

            $result = $handler->all($generator)->wait();

            expect($result['🚀'])->toBe('rocket');
            expect($result['🔥'])->toBe('fire');
        });
    });

    describe('Timeout Support', function () {
        it('maintains timeout functionality', function () {
            $handler = new PromiseCollectionHandler();
            $promise = delayedValue('too slow', 100);

            expect(fn () => $handler->timeout($promise, 0.02)->wait())
                ->toThrow(TimeoutException::class)
            ;
        });
    });
});
