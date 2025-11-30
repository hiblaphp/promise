<?php

declare(strict_types=1);

namespace Hibla\Promise\Interfaces;

/**
 * Interface for promises that can be cancelled to clean up resources.
 *
 * @template TValue The type of the value that the promise will resolve with.
 *
 * @extends PromiseInterface<TValue>
 */
interface CancellablePromiseInterface extends PromiseInterface
{
    /**
     * Cancel the promise and clean up associated resources.
     *
     * Once cancelled, the promise will be rejected with a cancellation exception
     * and any associated timers or handlers will be cleaned up.
     */
    public function cancel(): void;

    /**
     * Check if the promise has been cancelled.
     *
     * @return bool True if the promise has been cancelled, false otherwise.
     */
    public function isCancelled(): bool;

    /**
     * Set a callback to be executed when the promise is cancelled.
     *
     * This allows for custom cleanup logic when cancellation occurs.
     *
     * @param  callable(): void  $handler  The cancellation handler.
     */
    public function setCancelHandler(callable $handler): void;

    /**
     * Set the timer ID associated with this promise for cleanup purposes.
     *
     * @param  string  $timerId  The timer identifier.
     */
    public function setTimerId(string $timerId): void;

    /**
     * Attaches handlers for promise fulfillment and/or rejection.
     *
     * Returns a CancellablePromise to maintain cancellability through the chain.
     *
     * @template TResult
     *
     * @param  callable(TValue): (TResult|PromiseInterface<TResult>)|null  $onFulfilled
     * @param  callable(mixed): (TResult|PromiseInterface<TResult>)|null  $onRejected
     * @return CancellablePromiseInterface<TResult>
     */
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): CancellablePromiseInterface;

    /**
     * Attaches a rejection handler callback.
     *
     * Returns a CancellablePromise to maintain cancellability through the chain.
     *
     * @template TResult
     *
     * @param  callable(mixed): (TResult|PromiseInterface<TResult>)  $onRejected
     * @return CancellablePromiseInterface<TResult>
     */
    public function catch(callable $onRejected): CancellablePromiseInterface;

    /**
     * Attaches a callback that will be invoked when the promise is settled.
     *
     * Returns this CancellablePromise to maintain cancellability.
     *
     * @param  callable(): void  $onFinally
     * @return CancellablePromiseInterface<TValue>
     */
    public function finally(callable $onFinally): CancellablePromiseInterface;
}
