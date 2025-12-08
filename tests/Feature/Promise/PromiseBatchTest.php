<?php

declare(strict_types=1);

use function Hibla\delay;

use Hibla\Promise\Promise;

describe('Promise Batch Processing', function () {
    describe('Promise::batch', function () {
        it('processes tasks in batches with default batch size', function () {
            $executionOrder = [];
            $startTime = microtime(true);

            $tasks = [];
            for ($i = 0; $i < 25; $i++) {
                $tasks[] = function () use ($i, &$executionOrder) {
                    return delay(0.1)->then(function () use ($i, &$executionOrder) {
                        $executionOrder[] = $i;

                        return "task-{$i}";
                    });
                };
            }

            $result = Promise::batch($tasks, 5)->wait();
            $executionTime = microtime(true) - $startTime;

            expect($executionTime)->toBeLessThan(0.8);
            expect($result)->toHaveCount(25);
            expect($result[0])->toBe('task-0');
            expect($result[24])->toBe('task-24');
        });

        it('respects batch size parameter', function () {
            $startTime = microtime(true);

            $tasks = [];
            for ($i = 0; $i < 7; $i++) {
                $tasks[] = fn () => delay(0.1)->then(fn () => "task-{$i}");
            }

            $result = Promise::batch($tasks, 3)->wait();
            $executionTime = microtime(true) - $startTime;

            expect($result)->toHaveCount(7);
            expect($executionTime)->toBeGreaterThan(0.25);
            expect($executionTime)->toBeLessThan(0.5);
        });

        it('processes batches sequentially not concurrently', function () {
            $executionTimes = [];
            $startTime = microtime(true);

            $tasks = [];
            for ($i = 0; $i < 6; $i++) {
                $tasks[] = function () use ($i, &$executionTimes, $startTime) {
                    return delay(0.1)->then(function () use ($i, &$executionTimes, $startTime) {
                        $executionTimes[] = microtime(true) - $startTime;

                        return "task-{$i}";
                    });
                };
            }

            Promise::batch($tasks, 2)->wait();

            expect($executionTimes[0])->toBeLessThan(0.15);
            expect($executionTimes[2])->toBeGreaterThan(0.18);
            expect($executionTimes[4])->toBeGreaterThan(0.28);
        });

        it('handles empty task array', function () {
            $result = Promise::batch([], 5)->wait();

            expect($result)->toBe([]);
        });

        it('works with batch size larger than task count', function () {
            $tasks = [
                fn () => delay(0.05)->then(fn () => 'task-0'),
                fn () => delay(0.05)->then(fn () => 'task-1'),
                fn () => delay(0.05)->then(fn () => 'task-2'),
            ];

            $result = Promise::batch($tasks, 10)->wait();

            expect($result)->toHaveCount(3);
            expect($result)->toBe(['task-0', 'task-1', 'task-2']);
        });

        it('handles task failures within a batch', function () {
            try {
                $tasks = [
                    fn () => delay(0.05)->then(fn () => 'task-0'),
                    fn () => delay(0.05)->then(fn () => throw new Exception('batch error')),
                    fn () => delay(0.05)->then(fn () => 'task-2'),
                ];

                Promise::batch($tasks, 2)->wait();
                expect(false)->toBeTrue('Expected exception to be thrown');
            } catch (Exception $e) {
                expect($e->getMessage())->toBe('batch error');
            }
        });

        it('respects concurrency parameter when provided', function () {
            $startTime = microtime(true);

            $tasks = [];
            for ($i = 0; $i < 6; $i++) {
                $tasks[] = fn () => delay(0.1)->then(fn () => "task-{$i}");
            }

            $result = Promise::batch($tasks, 3, 2)->wait();
            $executionTime = microtime(true) - $startTime;

            expect($result)->toHaveCount(6);
            expect($executionTime)->toBeLessThan(0.6);
            expect($executionTime)->toBeGreaterThan(0.3);
        });

        it('maintains result order within and across batches', function () {
            $tasks = [];
            for ($i = 0; $i < 6; $i++) {
                $tasks[] = function () use ($i) {
                    return delay(0.05)->then(fn () => "task-{$i}");
                };
            }

            $result = Promise::batch($tasks, 2)->wait();

            expect($result)->toHaveCount(6);
            expect($result)->toContain('task-0');
            expect($result)->toContain('task-1');
            expect($result)->toContain('task-2');
            expect($result)->toContain('task-3');
            expect($result)->toContain('task-4');
            expect($result)->toContain('task-5');
        });
    });
});
