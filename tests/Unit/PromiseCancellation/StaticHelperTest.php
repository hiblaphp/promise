<?php

declare(strict_types=1);

use function Hibla\delay;

use Hibla\Promise\Exceptions\CancelledException;

use Hibla\Promise\Promise;

describe('Promise Static Utilities', function (): void {

    describe('propagateCancellation()', function (): void {

        it('bridges cancellation to the root of a promise chain', function (): void {
            $rootCancelled = false;

            $root = new Promise();
            $root->onCancel(function () use (&$rootCancelled): void {
                $rootCancelled = true;
            });

            $node1 = $root->then(fn ($v) => $v);
            $node2 = $node1->then(fn ($v) => $v);
            $leaf = $node2->then(fn ($v) => $v);

            $wrapped = Promise::propagateCancellation($leaf);

            $wrapped->cancel();

            expect($rootCancelled)->toBeTrue('Root onCancel handler must be fired')
                ->and($root->isCancelled())->toBeTrue('Root promise must be cancelled')
                ->and($leaf->isCancelled())->toBeTrue('Leaf promise must be cancelled')
            ;
        });

        it('does not interfere with normal resolution', function (): void {
            $root = new Promise();
            $wrapped = Promise::propagateCancellation($root);

            $root->resolve('success');

            expect($wrapped->isFulfilled())->toBeTrue()
                ->and($wrapped->wait())->toBe('success')
            ;
        });

        it('does not interfere with normal rejection', function (): void {
            $root = new Promise();
            $wrapped = Promise::propagateCancellation($root);

            $root->reject(new RuntimeException('failure'));

            expect(fn () => $wrapped->wait())->toThrow(RuntimeException::class, 'failure');
        });
    });

    describe('forwardCancellation()', function (): void {
        it('cancels a dynamically late-bound target promise passed by reference', function (): void {
            $targetCancelled = false;

            $source = new Promise();

            $target = null;

            Promise::forwardCancellation($source, $target);

            $target = new Promise();

            $target->onCancel(function () use (&$targetCancelled): void {
                $targetCancelled = true;
            });

            $source->cancel();

            expect($source->isCancelled())->toBeTrue()
                ->and($targetCancelled)->toBeTrue('Target onCancel handler must be fired despite late binding')
                ->and($target->isCancelled())->toBeTrue('Target promise must be cancelled despite late binding')
            ;
        });

        it('does nothing if the late-bound target is already settled', function (): void {
            $source = new Promise();

            $target = null;

            Promise::forwardCancellation($source, $target);

            $target = Promise::resolved('done');

            $source->cancel();

            expect($source->isCancelled())->toBeTrue()
                ->and($target->isFulfilled())->toBeTrue('Target should remain fulfilled')
            ;
        });

        it('does not crash if the target remains null when the source is cancelled', function (): void {
            $source = new Promise();
            $target = null;

            Promise::forwardCancellation($source, $target);

            // Should safely do nothing about $target
            $source->cancel();

            expect($source->isCancelled())->toBeTrue();
        });

        it('triggers cancelChain() on the target, not just a local cancel', function (): void {
            $targetRootCancelled = false;
            $source = new Promise();

            $targetRoot = new Promise();
            $targetRoot->onCancel(function () use (&$targetRootCancelled): void {
                $targetRootCancelled = true;
            });

            // Target is a leaf of a deep chain
            $targetLeaf = $targetRoot->then(fn ($x) => $x)->then(fn ($x) => $x);

            Promise::forwardCancellation($source, $targetLeaf);
            $source->cancel();

            expect($targetLeaf->isCancelled())->toBeTrue('Target leaf must be cancelled')
                ->and($targetRootCancelled)->toBeTrue('Cancellation must have climbed up to the target root')
            ;
        });
    });

    describe('uninterruptible()', function (): void {

        it('resolves transparently if not cancelled', function (): void {
            $internal = new Promise();
            $wrapped = Promise::uninterruptible($internal);

            $internal->resolve('success_value');

            expect($wrapped->wait())->toBe('success_value');
        });

        it('rejects transparently if not cancelled', function (): void {
            $internal = new Promise();
            $wrapped = Promise::uninterruptible($internal);

            $internal->reject(new RuntimeException('internal_failure'));

            expect(fn () => $wrapped->wait())->toThrow(RuntimeException::class, 'internal_failure');
        });

        it('prevents user cancellation from reaching the internal promise even with cancelChain()', function (): void {
            $internalCancelled = false;
            $internalFinallyRan = false;

            $internal = new Promise();
            $internal->onCancel(function () use (&$internalCancelled): void {
                $internalCancelled = true;
            });

            $internal->finally(function () use (&$internalFinallyRan): void {
                $internalFinallyRan = true;
            });

            $wrapped = Promise::uninterruptible($internal);

            // The User tries to aggressively cancel the whole chain
            $wrapped->cancelChain();

            expect($wrapped->isCancelled())->toBeTrue('Wrapped promise should be cancelled')
                ->and(fn () => $wrapped->wait())->toThrow(CancelledException::class)
            ;

            expect($internal->isPending())->toBeTrue('Internal promise must remain pending')
                ->and($internalCancelled)->toBeFalse('Internal onCancel must NOT be fired')
            ;

            $internal->resolve('background_done');

            delay(0.01)->wait();

            expect($internal->isFulfilled())->toBeTrue()
                ->and($internalFinallyRan)->toBeTrue('Internal finally() block must have executed successfully')
            ;
        });

        it('works correctly if the internal promise is ALREADY resolved', function (): void {
            $internal = Promise::resolved('already_done');

            $wrapped = Promise::uninterruptible($internal);

            expect($wrapped->wait())->toBe('already_done');
        });

        it('works correctly if the internal promise is ALREADY rejected', function (): void {
            $internal = Promise::rejected(new RuntimeException('already_failed'));

            $wrapped = Promise::uninterruptible($internal);

            expect(fn () => $wrapped->wait())->toThrow(RuntimeException::class, 'already_failed');
        });

        it('swallows internal rejections if the wrapper was already cancelled', function (): void {
            $internal = new Promise();
            $wrapped = Promise::uninterruptible($internal);

            $wrapped->cancel();

            $internal->reject(new RuntimeException('Should be swallowed quietly'));

            delay(0.01)->wait();

            expect($wrapped->isCancelled())->toBeTrue('Wrapper remains cancelled')
                ->and($internal->isRejected())->toBeTrue('Internal remains rejected')
            ;
        });

        it('leaves the wrapper pending forever if the internal promise was ALREADY cancelled', function (): void {
            $internal = new Promise();
            $internal->cancel();

            $wrapped = Promise::uninterruptible($internal);

            expect($wrapped->isPending())->toBeTrue();
        });
    });
});
