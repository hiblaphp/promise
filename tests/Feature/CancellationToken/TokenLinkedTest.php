<?php

declare(strict_types=1);

use Hibla\Promise\CancellationToken;
use Hibla\Promise\Exceptions\PromiseCancelledException;
use Hibla\Promise\Promise;

describe('CancellationToken::linked()', function () {
    it('returns a new uncancelled token when no sources provided', function () {
        $token = CancellationToken::linked();

        expect($token)->toBeInstanceOf(CancellationToken::class)
            ->and($token->isCancelled())->toBeFalse()
        ;
    });

    it('returns the same token when only one source provided', function () {
        $source = new CancellationToken();
        $linked = CancellationToken::linked($source);

        expect($linked)->toBe($source);
    });

    it('creates a new token for multiple sources', function () {
        $token1 = new CancellationToken();
        $token2 = new CancellationToken();
        $linked = CancellationToken::linked($token1, $token2);

        expect($linked)->toBeInstanceOf(CancellationToken::class)
            ->and($linked)->not->toBe($token1)
            ->and($linked)->not->toBe($token2)
        ;
    });

    it('cancels linked token when first source is cancelled', function () {
        $token1 = new CancellationToken();
        $token2 = new CancellationToken();
        $linked = CancellationToken::linked($token1, $token2);

        expect($linked->isCancelled())->toBeFalse();

        $token1->cancel();

        expect($linked->isCancelled())->toBeTrue()
            ->and($token2->isCancelled())->toBeFalse()
        ;
    });

    it('cancels linked token when second source is cancelled', function () {
        $token1 = new CancellationToken();
        $token2 = new CancellationToken();
        $linked = CancellationToken::linked($token1, $token2);

        expect($linked->isCancelled())->toBeFalse();

        $token2->cancel();

        expect($linked->isCancelled())->toBeTrue()
            ->and($token1->isCancelled())->toBeFalse()
        ;
    });

    it('cancels linked token when any of multiple sources is cancelled', function () {
        $token1 = new CancellationToken();
        $token2 = new CancellationToken();
        $token3 = new CancellationToken();
        $token4 = new CancellationToken();

        $linked = CancellationToken::linked($token1, $token2, $token3, $token4);

        expect($linked->isCancelled())->toBeFalse();

        $token3->cancel();

        expect($linked->isCancelled())->toBeTrue()
            ->and($token1->isCancelled())->toBeFalse()
            ->and($token2->isCancelled())->toBeFalse()
            ->and($token4->isCancelled())->toBeFalse()
        ;
    });

    it('returns already cancelled token if any source is already cancelled', function () {
        $cancelled = new CancellationToken();
        $cancelled->cancel();

        $normal1 = new CancellationToken();
        $normal2 = new CancellationToken();

        $linked = CancellationToken::linked($normal1, $cancelled, $normal2);

        expect($linked->isCancelled())->toBeTrue()
            ->and($normal1->isCancelled())->toBeFalse()
            ->and($normal2->isCancelled())->toBeFalse()
        ;
    });

    it('triggers onCancel callbacks when linked token is cancelled via source', function () {
        $token1 = new CancellationToken();
        $token2 = new CancellationToken();
        $linked = CancellationToken::linked($token1, $token2);

        $called = false;
        $linked->onCancel(function () use (&$called) {
            $called = true;
        });

        expect($called)->toBeFalse();

        $token1->cancel();

        expect($called)->toBeTrue();
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

    it('works with promises tracked by linked token', function () {
        $token1 = new CancellationToken();
        $token2 = new CancellationToken();
        $linked = CancellationToken::linked($token1, $token2);

        $promise = new Promise(function ($resolve) {
            // Long-running operation that won't complete in test
        });

        $linked->track($promise);

        expect($promise->isCancelled())->toBeFalse();

        $token1->cancel();

        expect($promise->isCancelled())->toBeTrue();
    });

    it('cancels tracked promise when any source token is cancelled', function () {
        $userToken = new CancellationToken();
        $timeoutToken = new CancellationToken();
        $linked = CancellationToken::linked($userToken, $timeoutToken);

        $promise = new Promise(function ($resolve, $reject) {
            // Simulate long-running operation
        });

        $linked->track($promise);

        expect($promise->isCancelled())->toBeFalse();

        $timeoutToken->cancel();

        expect($promise->isCancelled())->toBeTrue()
            ->and($linked->isCancelled())->toBeTrue()
        ;
    });

    it('handles multiple tracked promises cancellation', function () {
        $token1 = new CancellationToken();
        $token2 = new CancellationToken();
        $linked = CancellationToken::linked($token1, $token2);

        $promise1 = new Promise(fn () => null);
        $promise2 = new Promise(fn () => null);
        $promise3 = new Promise(fn () => null);

        $linked->track($promise1);
        $linked->track($promise2);
        $linked->track($promise3);

        expect($linked->getTrackedCount())->toBe(3);

        $token1->cancel();

        expect($promise1->isCancelled())->toBeTrue()
            ->and($promise2->isCancelled())->toBeTrue()
            ->and($promise3->isCancelled())->toBeTrue()
        ;
    });

    it('works with multiple levels of linked tokens', function () {
        $token1 = new CancellationToken();
        $token2 = new CancellationToken();
        $linked1 = CancellationToken::linked($token1, $token2);

        $token3 = new CancellationToken();
        $linked2 = CancellationToken::linked($linked1, $token3);

        expect($linked2->isCancelled())->toBeFalse();

        $token1->cancel();

        expect($linked1->isCancelled())->toBeTrue()
            ->and($linked2->isCancelled())->toBeTrue()
            ->and($token2->isCancelled())->toBeFalse()
            ->and($token3->isCancelled())->toBeFalse()
        ;
    });

    it('handles rapid sequential cancellations', function () {
        $tokens = [];
        for ($i = 0; $i < 10; $i++) {
            $tokens[] = new CancellationToken();
        }

        $linked = CancellationToken::linked(...$tokens);

        expect($linked->isCancelled())->toBeFalse();

        foreach ($tokens as $token) {
            $token->cancel();
        }

        expect($linked->isCancelled())->toBeTrue();
    });

    it('supports variadic spread operator for dynamic token lists', function () {
        $token1 = new CancellationToken();
        $token2 = new CancellationToken();
        $token3 = new CancellationToken();

        $tokenArray = [$token1, $token2, $token3];
        $linked = CancellationToken::linked(...$tokenArray);

        expect($linked->isCancelled())->toBeFalse();

        $token2->cancel();

        expect($linked->isCancelled())->toBeTrue();
    });

    it('throwIfCancelled works with linked tokens', function () {
        $token1 = new CancellationToken();
        $token2 = new CancellationToken();
        $linked = CancellationToken::linked($token1, $token2);

        expect(fn () => $linked->throwIfCancelled())->not->toThrow(Throwable::class);

        $token1->cancel();
        expect(fn () => $linked->throwIfCancelled())
            ->toThrow(PromiseCancelledException::class, 'Operation was cancelled')
        ;
    });

    it('executes onCancel callback immediately if already cancelled', function () {
        $token1 = new CancellationToken();
        $token2 = new CancellationToken();

        $token1->cancel();

        $linked = CancellationToken::linked($token1, $token2);

        $called = false;
        $linked->onCancel(function () use (&$called) {
            $called = true;
        });

        expect($called)->toBeTrue();
    });

    it('handles cancellation of nested promise chains', function () {
        $token1 = new CancellationToken();
        $token2 = new CancellationToken();
        $linked = CancellationToken::linked($token1, $token2);

        $promise = new Promise(function ($resolve) {
            // Never resolves
        });

        $chained = $promise->then(function ($value) {
            return $value * 2;
        })->then(function ($value) {
            return $value + 10;
        });

        $linked->track($promise);

        expect($promise->isCancelled())->toBeFalse()
            ->and($chained->isCancelled())->toBeFalse()
        ;

        $token2->cancel();

        expect($promise->isCancelled())->toBeTrue()
            ->and($chained->isCancelled())->toBeTrue()
        ;
    });

    it('handles promise that settles before cancellation', function () {
        $token1 = new CancellationToken();
        $token2 = new CancellationToken();
        $linked = CancellationToken::linked($token1, $token2);

        $promise = Promise::resolved('completed');
        $linked->track($promise);

        expect($promise->isFulfilled())->toBeTrue()
            ->and($promise->isCancelled())->toBeFalse()
        ;

        $token1->cancel();

        expect($promise->isFulfilled())->toBeTrue()
            ->and($promise->isCancelled())->toBeFalse()
        ;
    });

    it('clears tracked promises correctly', function () {
        $token1 = new CancellationToken();
        $token2 = new CancellationToken();
        $linked = CancellationToken::linked($token1, $token2);

        $promise1 = new Promise(fn () => null);
        $promise2 = new Promise(fn () => null);

        $linked->track($promise1);
        $linked->track($promise2);

        expect($linked->getTrackedCount())->toBe(2);

        $linked->clearTracked();

        expect($linked->getTrackedCount())->toBe(0);

        $token1->cancel();

        expect($promise1->isCancelled())->toBeFalse()
            ->and($promise2->isCancelled())->toBeFalse()
        ;
    });

    it('supports complex multi-source cancellation scenario', function () {
        $userToken = new CancellationToken();
        $timeoutToken = new CancellationToken();
        $resourceToken = new CancellationToken();

        $operationToken = CancellationToken::linked(
            $userToken,
            $timeoutToken,
            $resourceToken
        );

        $operation = new Promise(function ($resolve) {
            // Long-running operation
        });

        $operationToken->track($operation);

        expect($userToken->isCancelled())->toBeFalse()
            ->and($timeoutToken->isCancelled())->toBeFalse()
            ->and($resourceToken->isCancelled())->toBeFalse()
            ->and($operationToken->isCancelled())->toBeFalse()
            ->and($operation->isCancelled())->toBeFalse()
        ;

        $resourceToken->cancel();

        expect($operationToken->isCancelled())->toBeTrue()
            ->and($operation->isCancelled())->toBeTrue()
            ->and($userToken->isCancelled())->toBeFalse()
            ->and($timeoutToken->isCancelled())->toBeFalse()
        ;
    });

    it('handles empty callback list when cancelled', function () {
        $token1 = new CancellationToken();
        $token2 = new CancellationToken();
        $linked = CancellationToken::linked($token1, $token2);

        expect(fn () => $token1->cancel())->not->toThrow(Throwable::class);

        expect($linked->isCancelled())->toBeTrue();
    });

    it('maintains independence of source tokens', function () {
        $token1 = new CancellationToken();
        $token2 = new CancellationToken();
        $token3 = new CancellationToken();

        $linked1 = CancellationToken::linked($token1, $token2);
        $linked2 = CancellationToken::linked($token1, $token3);

        // Cancel token1 - both linked tokens should cancel
        $token1->cancel();

        expect($linked1->isCancelled())->toBeTrue()
            ->and($linked2->isCancelled())->toBeTrue()
            ->and($token2->isCancelled())->toBeFalse()
            ->and($token3->isCancelled())->toBeFalse();
    });
});
