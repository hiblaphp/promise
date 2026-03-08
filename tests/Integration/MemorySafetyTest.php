<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Promise\Promise;

const CHAIN_LENGTH = 2000;
const PASS_THRESHOLD_KB = 1000;

beforeEach(function () {
    Promise::setRejectionHandler(static fn() => null);
});

afterEach(function () {
    Promise::setRejectionHandler(null);
});

function runScenario(callable $build, ?callable $teardown = null): array
{
    gc_collect_cycles();

    $refs = $build();

    if ($teardown !== null) {
        $teardown($refs);
    }

    Hibla\EventLoop\Loop::runOnce();

    $beforeUnset = memory_get_usage();

    unset($refs);

    $cycles   = gc_collect_cycles();
    $afterGc  = memory_get_usage();
    $residual = $afterGc - ($beforeUnset - memory_get_usage() + $afterGc); 

    gc_collect_cycles();
    $baseline = memory_get_usage();
    $refs2    = $build();

    if ($teardown !== null) {
        $teardown($refs2);
    }

    Loop::runOnce();

    unset($refs2);

    $cycles   = gc_collect_cycles();
    $residual = memory_get_usage() - $baseline;

    return ['residual' => $residual, 'cycles' => $cycles];
}

describe('Promise memory leak scenarios (' . CHAIN_LENGTH . ' nodes)', function () {

    it('frees an abandoned pending chain', function () {
        $result = runScenario(function () {
            $root    = new Promise();
            $current = $root;

            for ($i = 0; $i < CHAIN_LENGTH; $i++) {
                $current = $current->then(fn($v) => $v);
            }

            return [$root, $current];
        });

        expect($result['residual'])->toBeLessThan(PASS_THRESHOLD_KB * 1024)
            ->and($result['cycles'])->toBeLessThan(50);
    });

    it('frees a resolved chain', function () {
        $result = runScenario(function () {
            $root    = new Promise(fn($resolve) => $resolve('start'));
            $current = $root;

            for ($i = 0; $i < CHAIN_LENGTH; $i++) {
                $current = $current->then(fn($v) => $v);
            }

            return [$root, $current];
        });

        expect($result['residual'])->toBeLessThan(PASS_THRESHOLD_KB * 1024)
            ->and($result['cycles'])->toBeLessThan(50);
    });

    it('frees a rejected chain', function () {
        $result = runScenario(function () {
            $root    = new Promise(fn($_, $reject) => $reject(new \RuntimeException('fail')));
            $current = $root;

            for ($i = 0; $i < CHAIN_LENGTH; $i++) {
                $current = $current->catch(fn(\Throwable $e) => throw $e);
            }

            return [$root, $current];
        });

        expect($result['residual'])->toBeLessThan(PASS_THRESHOLD_KB * 1024)
            ->and($result['cycles'])->toBeLessThan(50);
    });

    it('frees a cancelled chain', function () {
        $result = runScenario(
            function () {
                $root    = new Promise();
                $current = $root;

                for ($i = 0; $i < CHAIN_LENGTH; $i++) {
                    $current = $current->then(fn($v) => $v);
                }

                return [$root, $current];
            },
            function (array $refs): void {
                [$root] = $refs;
                $root->cancel();
            }
        );

        expect($result['residual'])->toBeLessThan(PASS_THRESHOLD_KB * 1024)
            ->and($result['cycles'])->toBeLessThan(50);
    });

    it('frees a fan-out of ' . CHAIN_LENGTH . ' abandoned children', function () {
        $result = runScenario(function () {
            $root     = new Promise();
            $branches = [];

            for ($i = 0; $i < CHAIN_LENGTH; $i++) {
                $branches[] = $root->then(fn($v) => $v);
            }

            return [$root, $branches];
        });

        expect($result['residual'])->toBeLessThan(PASS_THRESHOLD_KB * 1024)
            ->and($result['cycles'])->toBeLessThan(50);
    });

    it('frees a chain with onCancel handlers', function () {
        $result = runScenario(function () {
            $root    = new Promise();
            $current = $root;

            for ($i = 0; $i < CHAIN_LENGTH; $i++) {
                $child = $current->then(fn($v) => $v);
                $child->onCancel(static fn() => null);
                $current = $child;
            }

            return [$root, $current];
        });

        expect($result['residual'])->toBeLessThan(PASS_THRESHOLD_KB * 1024)
            ->and($result['cycles'])->toBeLessThan(50);
    });

    it('frees a mixed then/catch/finally chain', function () {
        $result = runScenario(function () {
            $root    = new Promise();
            $current = $root;

            for ($i = 0; $i < CHAIN_LENGTH; $i++) {
                $current = $current
                    ->then(fn($v) => $v)
                    ->catch(fn(\Throwable $e) => throw $e)
                    ->finally(static fn() => null);
            }

            return [$root, $current];
        });

        expect($result['residual'])->toBeLessThan(PASS_THRESHOLD_KB * 1024)
            ->and($result['cycles'])->toBeLessThan(50);
    });

    it('frees a chain cancelled via cancelChain() from the tail', function () {
        $result = runScenario(
            function () {
                $root    = new Promise();
                $current = $root;

                for ($i = 0; $i < CHAIN_LENGTH; $i++) {
                    $current = $current->then(fn($v) => $v);
                }

                return [$root, $current];
            },
            function (array $refs): void {
                [, $tail] = $refs;
                $tail->cancelChain();
            }
        );

        expect($result['residual'])->toBeLessThan(PASS_THRESHOLD_KB * 1024)
            ->and($result['cycles'])->toBeLessThan(50);
    });
});