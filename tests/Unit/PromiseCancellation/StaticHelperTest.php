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
    });

    describe('forwardCancellation()', function (): void {

        it('cancels the target promise when the source promise is cancelled', function (): void {
            $targetCancelled = false;

            $source = new Promise();
            $target = new Promise();

            $target->onCancel(function () use (&$targetCancelled): void {
                $targetCancelled = true;
            });

            Promise::forwardCancellation($source, $target);

            $source->cancel();

            expect($source->isCancelled())->toBeTrue()
                ->and($targetCancelled)->toBeTrue('Target onCancel handler must be fired')
                ->and($target->isCancelled())->toBeTrue('Target promise must be cancelled')
            ;
        });

        it('does nothing if the target is already settled', function (): void {
            $source = new Promise();
            $target = Promise::resolved('done');

            Promise::forwardCancellation($source, $target);

            $source->cancel();

            expect($source->isCancelled())->toBeTrue()
                ->and($target->isFulfilled())->toBeTrue('Target should remain fulfilled')
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
    });
});
