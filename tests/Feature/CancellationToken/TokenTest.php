<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Promise\CancellationToken;
use Hibla\Promise\Exceptions\PromiseCancelledException;
use Hibla\Promise\Promise;

describe('CancellationToken (Pure Promise Layer)', function () {
    afterEach(function () {
        Loop::reset();
    });

    describe('Basic State Management', function () {
        it('starts in uncancelled state', function () {
            $token = new CancellationToken();

            expect($token->isCancelled())->toBeFalse();
        });

        it('transitions to cancelled state when cancelled', function () {
            $token = new CancellationToken();

            $token->cancel();

            expect($token->isCancelled())->toBeTrue();
        });

        it('remains cancelled after multiple cancel calls', function () {
            $token = new CancellationToken();

            $token->cancel();
            $token->cancel();
            $token->cancel();

            expect($token->isCancelled())->toBeTrue();
        });
    });

    describe('Promise Tracking', function () {
        it('tracks a pending promise', function () {
            $token = new CancellationToken();
            $promise = new Promise(function () {
                // Never settles
            });

            $token->track($promise);

            expect($token->getTrackedCount())->toBe(1);
        });

        it('tracks multiple promises', function () {
            $token = new CancellationToken();

            $promise1 = new Promise(function () {});
            $promise2 = new Promise(function () {});
            $promise3 = new Promise(function () {});

            $token->track($promise1);
            $token->track($promise2);
            $token->track($promise3);

            expect($token->getTrackedCount())->toBe(3);
        });

        it('does not track already settled promise', function () {
            $token = new CancellationToken();
            $promise = Promise::resolved('value');

            $token->track($promise);

            expect($token->getTrackedCount())->toBe(0);
        });

        it('cancels tracked promise when token is cancelled', function () {
            $token = new CancellationToken();
            $promise = new Promise(function () {});

            $token->track($promise);

            expect($promise->isCancelled())->toBeFalse();

            $token->cancel();

            expect($promise->isCancelled())->toBeTrue();
        });

        it('cancels all tracked promises', function () {
            $token = new CancellationToken();

            $promise1 = new Promise(function () {});
            $promise2 = new Promise(function () {});
            $promise3 = new Promise(function () {});

            $token->track($promise1);
            $token->track($promise2);
            $token->track($promise3);

            $token->cancel();

            expect($promise1->isCancelled())->toBeTrue()
                ->and($promise2->isCancelled())->toBeTrue()
                ->and($promise3->isCancelled())->toBeTrue()
            ;
        });

        it('immediately cancels promise if token already cancelled', function () {
            $token = new CancellationToken();
            $token->cancel();

            $promise = new Promise(function () {});
            $token->track($promise);

            expect($promise->isCancelled())->toBeTrue();
        });

        it('automatically untracks promise when it resolves', function () {
            $token = new CancellationToken();

            $promise = new Promise(function ($resolve) {
                Loop::defer(function () use ($resolve) {
                    $resolve('value');
                });
            });

            $token->track($promise);

            expect($token->getTrackedCount())->toBe(1);

            $promise->wait();

            expect($token->getTrackedCount())->toBe(0);
        });

        it('automatically untracks promise when it rejects', function () {
            $token = new CancellationToken();

            $promise = new Promise(function ($resolve, $reject) {
                Loop::nextTick(function () use ($reject) {
                    $reject(new RuntimeException('error'));
                });
            });

            $promise = $promise->catch(function () {});

            $token->track($promise);

            expect($token->getTrackedCount())->toBe(1);

            try {
                $promise->wait();
            } catch (RuntimeException $e) {
                // Expected
            }

            expect($token->getTrackedCount())->toBe(0);
        });

        it('manually untracks a specific promise', function () {
            $token = new CancellationToken();

            $promise1 = new Promise(function () {});
            $promise2 = new Promise(function () {});

            $token->track($promise1);
            $token->track($promise2);

            expect($token->getTrackedCount())->toBe(2);

            $token->untrack($promise1);

            expect($token->getTrackedCount())->toBe(1);

            $token->cancel();

            expect($promise1->isCancelled())->toBeFalse();
            expect($promise2->isCancelled())->toBeTrue();
        });

        it('clears all tracked promises without cancelling them', function () {
            $token = new CancellationToken();

            $promise1 = new Promise(function () {});
            $promise2 = new Promise(function () {});
            $promise3 = new Promise(function () {});

            $token->track($promise1);
            $token->track($promise2);
            $token->track($promise3);

            expect($token->getTrackedCount())->toBe(3);

            $token->clearTracked();

            expect($token->getTrackedCount())->toBe(0);

            $token->cancel();

            expect($promise1->isCancelled())->toBeFalse()
                ->and($promise2->isCancelled())->toBeFalse()
                ->and($promise3->isCancelled())->toBeFalse()
            ;
        });

        it('tracks the same promise multiple times', function () {
            $token = new CancellationToken();
            $promise = new Promise(function () {});

            $token->track($promise);
            $token->track($promise);
            $token->track($promise);

            expect($token->getTrackedCount())->toBe(3);

            $token->cancel();

            expect($promise->isCancelled())->toBeTrue();
        });

        it('does not track settled promises', function () {
            $token = new CancellationToken();

            $resolved = Promise::resolved('value');
            $rejected = Promise::rejected(new RuntimeException('error'));

            $token->track($resolved);
            $token->track($rejected);

            expect($rejected->getReason())->toBeInstanceOf(RuntimeException::class);
            expect($token->getTrackedCount())->toBe(0);
        });
    });

    describe('Cancellation Callbacks', function () {
        it('executes callback when cancelled', function () {
            $token = new CancellationToken();
            $called = false;

            $token->onCancel(function () use (&$called) {
                $called = true;
            });

            expect($called)->toBeFalse();

            $token->cancel();

            expect($called)->toBeTrue();
        });

        it('executes multiple callbacks in registration order', function () {
            $token = new CancellationToken();
            $order = [];

            $token->onCancel(function () use (&$order) {
                $order[] = 1;
            });

            $token->onCancel(function () use (&$order) {
                $order[] = 2;
            });

            $token->onCancel(function () use (&$order) {
                $order[] = 3;
            });

            $token->cancel();

            expect($order)->toBe([1, 2, 3]);
        });

        it('immediately executes callback if already cancelled', function () {
            $token = new CancellationToken();
            $token->cancel();

            $called = false;

            $token->onCancel(function () use (&$called) {
                $called = true;
            });

            expect($called)->toBeTrue();
        });

        it('does not execute callback multiple times on repeated cancels', function () {
            $token = new CancellationToken();
            $count = 0;

            $token->onCancel(function () use (&$count) {
                $count++;
            });

            $token->cancel();
            $token->cancel();
            $token->cancel();

            expect($count)->toBe(1);
        });

        it('clears callbacks after cancellation', function () {
            $token = new CancellationToken();
            $count = 0;

            $token->onCancel(function () use (&$count) {
                $count++;
            });

            $token->cancel();

            expect($count)->toBe(1);

            $token->onCancel(function () use (&$count) {
                $count++;
            });

            expect($count)->toBe(2);
        });

        it('allows callbacks to access token state', function () {
            $token = new CancellationToken();
            $tokenWasCancelled = null;

            $token->onCancel(function () use ($token, &$tokenWasCancelled) {
                $tokenWasCancelled = $token->isCancelled();
            });

            $token->cancel();

            expect($tokenWasCancelled)->toBeTrue();
        });
    });

    describe('throwIfCancelled()', function () {
        it('throws exception when cancelled', function () {
            $token = new CancellationToken();
            $token->cancel();

            expect(fn () => $token->throwIfCancelled())
                ->toThrow(PromiseCancelledException::class, 'Operation was cancelled')
            ;
        });

        it('does not throw when not cancelled', function () {
            $token = new CancellationToken();

            $token->throwIfCancelled();

            expect(true)->toBeTrue(); // No exception thrown
        });

        it('can be called multiple times', function () {
            $token = new CancellationToken();

            $token->throwIfCancelled();
            $token->throwIfCancelled();
            $token->throwIfCancelled();

            $token->cancel();

            expect(fn () => $token->throwIfCancelled())
                ->toThrow(PromiseCancelledException::class)
            ;
        });
    });

    describe('cancelAfter()', function () {
        it('cancels token after specified delay', function () {
            $token = new CancellationToken();

            $token->cancelAfter(0.1);

            expect($token->isCancelled())->toBeFalse();

            Loop::run();

            expect($token->isCancelled())->toBeTrue();
        });

        it('cancels all tracked promises after delay', function () {
            $token = new CancellationToken();

            $promise1 = new Promise(function () {});
            $promise2 = new Promise(function () {});

            $token->track($promise1);
            $token->track($promise2);

            $token->cancelAfter(0.05);

            expect($promise1->isCancelled())->toBeFalse();
            expect($promise2->isCancelled())->toBeFalse();

            Loop::run();

            expect($promise1->isCancelled())->toBeTrue();
            expect($promise2->isCancelled())->toBeTrue();
        });

        it('supports fractional seconds', function () {
            $token = new CancellationToken();

            $token->cancelAfter(0.05);

            Loop::run();

            expect($token->isCancelled())->toBeTrue();
        });

        it('has no effect if token already cancelled', function () {
            $token = new CancellationToken();
            $token->cancel();

            expect($token->isCancelled())->toBeTrue();

            $callbackCount = 0;
            $token->onCancel(function () use (&$callbackCount) {
                $callbackCount++;
            });

            expect($callbackCount)->toBe(1);

            $token->cancelAfter(0.01);

            Loop::run();

            expect($callbackCount)->toBe(1);
        });
    });

    describe('Promise Chain Cancellation', function () {
        it('cancels child promises in chain', function () {
            $token = new CancellationToken();

            $promise = new Promise(function () {});
            $child = $promise->then(function ($value) {
                return $value * 2;
            });

            $token->track($promise);

            $token->cancel();

            expect($promise->isCancelled())->toBeTrue();
            expect($child->isCancelled())->toBeTrue();
        });

        it('cancels multiple levels of promise chains', function () {
            $token = new CancellationToken();

            $promise = new Promise(function () {});
            $child1 = $promise->then(fn ($v) => $v);
            $child2 = $child1->then(fn ($v) => $v);
            $child3 = $child2->then(fn ($v) => $v);

            $token->track($promise);

            $token->cancel();

            expect($promise->isCancelled())->toBeTrue()
                ->and($child1->isCancelled())->toBeTrue()
                ->and($child2->isCancelled())->toBeTrue()
                ->and($child3->isCancelled())->toBeTrue()
            ;
        });

        it('does not affect already settled chain members', function () {
            $token = new CancellationToken();

            $promise = Promise::resolved(10);
            $child = $promise->then(function ($value) {
                return $value * 2;
            });

            $result = $child->wait();

            $token->track($promise);
            $token->cancel();

            expect($promise->isFulfilled())->toBeTrue()
                ->and($child->isFulfilled())->toBeTrue()
                ->and($result)->toBe(20)
            ;
        });
    });

    describe('CancellationToken::linked()', function () {
        it('creates linked token from multiple sources', function () {
            $token1 = new CancellationToken();
            $token2 = new CancellationToken();
            $token3 = new CancellationToken();

            $linked = CancellationToken::linked($token1, $token2, $token3);

            expect($linked)->toBeInstanceOf(CancellationToken::class)
                ->and($linked->isCancelled())->toBeFalse()
            ;
        });

        it('cancels linked token when any source is cancelled', function () {
            $token1 = new CancellationToken();
            $token2 = new CancellationToken();
            $token3 = new CancellationToken();

            $linked = CancellationToken::linked($token1, $token2, $token3);

            $token2->cancel();

            expect($linked->isCancelled())->toBeTrue()
                ->and($token1->isCancelled())->toBeFalse()
                ->and($token3->isCancelled())->toBeFalse()
            ;
        });

        it('returns same token when only one source provided', function () {
            $token = new CancellationToken();
            $linked = CancellationToken::linked($token);

            expect($linked)->toBe($token);
        });

        it('returns new uncancelled token when no sources provided', function () {
            $linked = CancellationToken::linked();

            expect($linked)->toBeInstanceOf(CancellationToken::class)
                ->and($linked->isCancelled())->toBeFalse()
            ;
        });

        it('returns immediately cancelled token if any source already cancelled', function () {
            $cancelled = new CancellationToken();
            $cancelled->cancel();

            $normal = new CancellationToken();

            $linked = CancellationToken::linked($cancelled, $normal);

            expect($linked->isCancelled())->toBeTrue()
                ->and($normal->isCancelled())->toBeFalse()
            ;
        });

        it('does not cancel source tokens when linked token is cancelled directly', function () {
            $token1 = new CancellationToken();
            $token2 = new CancellationToken();

            $linked = CancellationToken::linked($token1, $token2);

            $linked->cancel();

            expect($linked->isCancelled())->toBeTrue()
                ->and($token1->isCancelled())->toBeFalse()
                ->and($token2->isCancelled())->toBeFalse()
            ;
        });

        it('supports nested linked tokens', function () {
            $token1 = new CancellationToken();
            $token2 = new CancellationToken();
            $linked1 = CancellationToken::linked($token1, $token2);

            $token3 = new CancellationToken();
            $linked2 = CancellationToken::linked($linked1, $token3);

            $token1->cancel();

            expect($linked1->isCancelled())->toBeTrue()
                ->and($linked2->isCancelled())->toBeTrue()
                ->and($token2->isCancelled())->toBeFalse()
                ->and($token3->isCancelled())->toBeFalse()
            ;
        });

        it('tracks promises on linked token', function () {
            $token1 = new CancellationToken();
            $token2 = new CancellationToken();
            $linked = CancellationToken::linked($token1, $token2);

            $promise = new Promise(function () {});
            $linked->track($promise);

            expect($linked->getTrackedCount())->toBe(1);

            $token1->cancel();

            expect($promise->isCancelled())->toBeTrue()
                ->and($linked->getTrackedCount())->toBe(0)
            ;
        });

        it('supports variadic spread operator', function () {
            $tokens = [
                new CancellationToken(),
                new CancellationToken(),
                new CancellationToken(),
            ];

            $linked = CancellationToken::linked(...$tokens);

            $tokens[1]->cancel();

            expect($linked->isCancelled())->toBeTrue();
        });
    });

    describe('Edge Cases and Error Handling', function () {
        it('handles tracking null promise gracefully', function () {
            $token = new CancellationToken();

            expect(fn () => $token->untrack(new Promise(fn () => null)))
                ->not->toThrow(Throwable::class)
            ;
        });

        it('handles cancellation with no tracked promises', function () {
            $token = new CancellationToken();

            expect(fn () => $token->cancel())
                ->not->toThrow(Throwable::class)
            ;

            expect($token->isCancelled())->toBeTrue();
        });

        it('handles cancellation with no callbacks', function () {
            $token = new CancellationToken();

            expect(fn () => $token->cancel())
                ->not->toThrow(Throwable::class)
            ;
        });

        it('handles untracking non-tracked promise', function () {
            $token = new CancellationToken();
            $promise = new Promise(function () {});

            expect(fn () => $token->untrack($promise))
                ->not->toThrow(Throwable::class)
            ;
        });

        it('handles clearing empty tracked list', function () {
            $token = new CancellationToken();

            expect(fn () => $token->clearTracked())
                ->not->toThrow(Throwable::class)
            ;
        });

        it('maintains tracked count accuracy with mixed operations', function () {
            $token = new CancellationToken();

            $p1 = new Promise(function () {});
            $p2 = new Promise(function () {});
            $p3 = new Promise(function () {});

            $token->track($p1);
            expect($token->getTrackedCount())->toBe(1);

            $token->track($p2);
            expect($token->getTrackedCount())->toBe(2);

            $token->untrack($p1);
            expect($token->getTrackedCount())->toBe(1);

            $token->track($p3);
            expect($token->getTrackedCount())->toBe(2);

            $token->clearTracked();
            expect($token->getTrackedCount())->toBe(0);
        });
    });

    describe('Integration with Promise Static Methods', function () {
        it('works with Promise::race()', function () {
            $token = new CancellationToken();

            $slow = new Promise(function ($resolve) use ($token) {
                $token->onCancel(function () use ($resolve) {
                    // Don't resolve
                });
            });

            $fast = Promise::resolved('fast');

            $token->track($slow);

            $race = Promise::race([$slow, $fast]);
            $result = $race->wait();

            expect($result)->toBe('fast');
        });

        it('works with Promise::all()', function () {
            $token = new CancellationToken();

            $p1 = Promise::resolved(1);
            $p2 = Promise::resolved(2);
            $p3 = new Promise(function () {
                // Never settles
            });

            $token->track($p3);

            $all = Promise::all([$p1, $p2, $p3]);

            $token->track($all);

            $token->cancel();

            expect($p3->isCancelled())->toBeTrue()
                ->and($all->isCancelled())->toBeTrue()
            ;
        });

        it('works with Promise::allSettled()', function () {
            $token = new CancellationToken();

            $p1 = Promise::resolved(1);
            $p2 = Promise::rejected(new RuntimeException('error'));
            $p3 = new Promise(function () {});

            $token->track($p3);

            Promise::allSettled([$p1, $p2, $p3]);

            $token->cancel();

            expect($p3->isCancelled())->toBeTrue();
        });
    });

    describe('Real-World Usage Patterns', function () {
        it('implements timeout pattern', function () {
            $token = new CancellationToken();

            $operation = new Promise(function () {
                // Long-running operation
            });

            $token->track($operation);
            $token->cancelAfter(0.05);

            Loop::run();

            expect($operation->isCancelled())->toBeTrue();
        });

        it('implements user cancellation pattern', function () {
            $token = new CancellationToken();

            $promises = [];
            for ($i = 0; $i < 5; $i++) {
                $promises[] = $token->track(new Promise(function () {}));
            }

            $token->cancel();

            foreach ($promises as $promise) {
                expect($promise->isCancelled())->toBeTrue();
            }
        });

        it('implements resource cleanup pattern', function () {
            $token = new CancellationToken();
            $resourceCleaned = false;

            $token->onCancel(function () use (&$resourceCleaned) {
                $resourceCleaned = true;
            });

            $operation = new Promise(function () {});
            $token->track($operation);

            $token->cancel();

            expect($resourceCleaned)->toBeTrue();
        });

        it('implements coordinated cancellation pattern', function () {
            $mainToken = new CancellationToken();
            $subToken1 = new CancellationToken();
            $subToken2 = new CancellationToken();

            $mainToken->onCancel(function () use ($subToken1, $subToken2) {
                $subToken1->cancel();
                $subToken2->cancel();
            });

            $operation1 = new Promise(function () {});
            $operation2 = new Promise(function () {});

            $subToken1->track($operation1);
            $subToken2->track($operation2);

            $mainToken->cancel();

            expect($operation1->isCancelled())->toBeTrue()
                ->and($operation2->isCancelled())->toBeTrue();
        });
    });
});
