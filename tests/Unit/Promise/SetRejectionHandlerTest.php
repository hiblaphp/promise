<?php

declare(strict_types=1);

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

/**
 * Helper to force __destruct by scoping the promise inside a closure
 */
function withPromise(callable $factory): void
{
    $factory();
    // Promise goes out of scope here, __destruct fires
}

afterEach(function () {
    Promise::setRejectionHandler(null);
});

describe('setRejectionHandler() return value', function () {

    it('returns null when no handler was previously set', function () {
        $previous = Promise::setRejectionHandler(fn () => null);

        expect($previous)->toBeNull();
    });

    it('returns the previously registered handler', function () {
        $first = fn () => null;
        Promise::setRejectionHandler($first);

        $previous = Promise::setRejectionHandler(fn () => null);

        expect($previous)->toBe($first);
    });

    it('returns null after handler is cleared with null', function () {
        Promise::setRejectionHandler(fn () => null);
        Promise::setRejectionHandler(null);

        $previous = Promise::setRejectionHandler(fn () => null);

        expect($previous)->toBeNull();
    });

    it('supports save and restore pattern', function () {
        $original = fn (mixed $reason) => null;
        Promise::setRejectionHandler($original);

        $previous = Promise::setRejectionHandler(fn () => null);
        Promise::setRejectionHandler($previous);

        $captured = null;
        $probe = Promise::setRejectionHandler(function () use (&$captured) {
            $captured = 'probe';
        });

        expect($probe)->toBe($original);
    });
});

describe('custom handler invocation', function () {

    it('calls the custom handler instead of writing to stderr', function () {
        $called = false;

        Promise::setRejectionHandler(function () use (&$called) {
            $called = true;
        });

        withPromise(fn () => Promise::rejected(new RuntimeException('oops')));

        expect($called)->toBeTrue();
    });

    it('passes the rejection reason to the handler', function () {
        $capturedReason = null;
        $exception = new RuntimeException('something went wrong');

        Promise::setRejectionHandler(function (mixed $reason) use (&$capturedReason) {
            $capturedReason = $reason;
        });

        withPromise(fn () => Promise::rejected($exception));

        expect($capturedReason)->toBe($exception);
    });

    it('passes the promise instance to the handler', function () {
        $capturedPromise = null;

        Promise::setRejectionHandler(function (mixed $reason, PromiseInterface $promise) use (&$capturedPromise) {
            $capturedPromise = $promise;
        });

        withPromise(fn () => Promise::rejected(new RuntimeException('oops')));

        expect($capturedPromise)->toBeInstanceOf(PromiseInterface::class);
    });

    it('passes mixed reason (non-Throwable) to the handler', function () {
        $capturedReason = null;

        Promise::setRejectionHandler(function (mixed $reason) use (&$capturedReason) {
            $capturedReason = $reason;
        });

        withPromise(fn () => Promise::rejected('plain string reason'));

        expect($capturedReason)->toBe('plain string reason');
    });

    it('does not write to stderr when a custom handler is set', function () {
        Promise::setRejectionHandler(fn () => null);

        $handlerCalled = false;
        Promise::setRejectionHandler(function () use (&$handlerCalled) {
            $handlerCalled = true;
        });

        withPromise(fn () => Promise::rejected(new RuntimeException('oops')));

        expect($handlerCalled)->toBeTrue();
    });
});

describe('handler is not invoked when rejection is handled', function () {

    it('does not call the handler when catch() is attached', function () {
        $called = false;

        Promise::setRejectionHandler(function () use (&$called) {
            $called = true;
        });

        withPromise(function () {
            $promise = Promise::rejected(new RuntimeException('handled'));
            $promise->catch(fn () => null);
        });

        expect($called)->toBeFalse();
    });

    it('does not call the handler when then() with onRejected is attached', function () {
        $called = false;

        Promise::setRejectionHandler(function () use (&$called) {
            $called = true;
        });

        withPromise(function () {
            $promise = Promise::rejected(new RuntimeException('handled'));
            $promise->then(null, fn () => null);
        });

        expect($called)->toBeFalse();
    });

    it('does not call the handler when wait() is called', function () {
        $called = false;

        Promise::setRejectionHandler(function () use (&$called) {
            $called = true;
        });

        withPromise(function () {
            $promise = Promise::rejected(new RuntimeException('waited'));

            try {
                $promise->wait();
            } catch (Throwable) {
                // expected
            }
        });

        expect($called)->toBeFalse();
    });

    it('does not call the handler for a fulfilled promise', function () {
        $called = false;

        Promise::setRejectionHandler(function () use (&$called) {
            $called = true;
        });

        withPromise(fn () => Promise::resolved('ok'));

        expect($called)->toBeFalse();
    });

    it('does not call the handler for a cancelled promise', function () {
        $called = false;

        Promise::setRejectionHandler(function () use (&$called) {
            $called = true;
        });

        withPromise(function () {
            $promise = new Promise();
            $promise->cancel();
        });

        expect($called)->toBeFalse();
    });

    it('does not call the handler for a pending promise', function () {
        $called = false;

        Promise::setRejectionHandler(function () use (&$called) {
            $called = true;
        });

        withPromise(fn () => new Promise());

        expect($called)->toBeFalse();
    });
});

describe('restoring default behaviour', function () {

    it('restores default stderr output when null is passed', function () {
        Promise::setRejectionHandler(fn () => null);
        Promise::setRejectionHandler(null);

        $customCalled = false;

        $previous = Promise::setRejectionHandler(function () use (&$customCalled) {
            $customCalled = true;
        });

        expect($previous)->toBeNull();
    });
});

describe('handler isolation across tests', function () {

    it('handlers do not leak between tests when save/restore is used', function () {
        $previous = Promise::setRejectionHandler(fn () => null);

        Promise::setRejectionHandler($previous);

        $restored = Promise::setRejectionHandler(fn () => null);
        expect($restored)->toBeNull();
    });
});
