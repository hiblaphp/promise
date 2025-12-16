<?php

declare(strict_types=1);

namespace Hibla\Promise;

use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\PromiseCancelledException;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * A cancellation token for controlling and coordinating async operation cancellation.
 *
 * CancellationToken provides a clean, explicit way to signal cancellation to ongoing
 * asynchronous operations. Unlike implicit parent-child tracking, tokens are passed
 * explicitly, giving you full control over cancellation scope and propagation.
 */
final class CancellationToken
{
    /**
     * @var array<int, PromiseInterface<mixed>>
     */
    private array $trackedPromises = [];

    /**
     * @var array<int, callable>
     */
    private array $cancelCallbacks = [];

    /**
     * @var array<int, int>
     */
    private array $promiseKeyMap = [];

    private int $nextPromiseKey = 0;

    private bool $cancelled = false;

    /**
     * Create a linked cancellation token that cancels when ANY source token cancels.
     *
     * This is useful for combining multiple cancellation sources (user cancellation,
     * timeout, system shutdown, etc.) into a single token. The linked token will
     * automatically cancel if any of its source tokens cancel.
     *
     * **Use Cases:**
     * - Combine user cancellation with timeout
     * - Coordinate cancellation across multiple operations
     * - Create fallback cancellation strategies
     *
     * @param CancellationToken ...$sources One or more source tokens to link
     * @return self A new token that cancels when any source cancels
     */
    public static function linked(self ...$sources): self
    {
        if (\count($sources) === 0) {
            return new self();
        }

        if (\count($sources) === 1) {
            return $sources[0];
        }

        $linked = new self();

        foreach ($sources as $source) {
            if ($source->isCancelled()) {
                $linked->cancel();

                return $linked;
            }
        }

        foreach ($sources as $source) {
            $source->onCancel(function () use ($linked): void {
                if (! $linked->isCancelled()) {
                    $linked->cancel();
                }
            });
        }

        return $linked;
    }

    /**
     * Check if cancellation has been requested.
     *
     * @return bool True if cancelled, false otherwise
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * Request cancellation of all tracked operations.
     *
     * This method:
     * 1. Marks the token as cancelled
     * 2. Invokes all registered callbacks (for cleanup operations)
     * 3. Cancels all tracked promises
     *
     * Calling `cancel()` multiple times is safe - subsequent calls have no effect.
     */
    public function cancel(): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;

        $callbacks = $this->cancelCallbacks;
        $this->cancelCallbacks = [];

        foreach ($callbacks as $callback) {
            $callback();
        }

        $promises = $this->trackedPromises;
        $this->trackedPromises = [];
        $this->promiseKeyMap = [];

        foreach ($promises as $promise) {
            if (! $promise->isSettled() && ! $promise->isCancelled()) {
                $promise->cancel();
            }
        }
    }

    /**
     * Track a promise for automatic cancellation.
     *
     * When you track a promise, it will be automatically cancelled if the token
     * is cancelled. The promise is automatically untracked when it settles.
     *
     * This is useful for managing multiple concurrent operations that should all
     * be cancelled together.
     *
     * @template TValue
     * @param PromiseInterface<TValue> $promise The promise to track
     * @return PromiseInterface<TValue> The same promise (for chaining)
     */
    public function track(PromiseInterface $promise): PromiseInterface
    {
        if ($promise->isSettled()) {
            return $promise;
        }

        if ($this->cancelled) {
            if (! $promise->isCancelled()) {
                $promise->cancel();
            }

            return $promise;
        }

        $key = $this->nextPromiseKey++;
        $this->trackedPromises[$key] = $promise;

        $promiseId = spl_object_id($promise);
        $this->promiseKeyMap[$promiseId] = $key;

        $weakThis = \WeakReference::create($this);

        $promise->finally(static function () use ($weakThis, $promiseId): void {
            $token = $weakThis->get();
            if ($token !== null) {
                $token->untrackById($promiseId);
            }
        });

        return $promise;
    }

    /**
     * Stop tracking a promise.
     *
     * After untracking, the promise will no longer be automatically cancelled
     * when the token is cancelled. This is rarely needed as promises are
     * automatically untracked when they settle.
     *
     * @param PromiseInterface<mixed> $promise The promise to stop tracking
     *
     */
    public function untrack(PromiseInterface $promise): void
    {
        $this->untrackById(spl_object_id($promise));
    }

    /**
     * Schedule automatic cancellation after a specified duration.
     *
     * This is a convenient way to implement timeouts without manually managing
     * timers. The token will automatically cancel after the specified time.
     *
     * @param float $seconds Number of seconds until automatic cancellation
     */
    public function cancelAfter(float $seconds): void
    {
        Loop::addTimer($seconds, function () {
            $this->cancel();
        });
    }

    /**
     * Throw an exception if cancellation has been requested.
     *
     * This is the primary way to check for cancellation in your async code.
     * Place strategic calls to `throwIfCancelled()` at points where it's safe
     * to stop the operation.
     *
     * @throws PromiseCancelledException If the token has been cancelled
     */
    public function throwIfCancelled(): void
    {
        if ($this->cancelled) {
            throw new PromiseCancelledException('Operation was cancelled');
        }
    }

    /**
     * Register a callback to execute when cancellation occurs.
     *
     * Use this for cleanup operations like closing connections, releasing resources,
     * or logging cancellation events. Callbacks are executed in registration order.
     *
     * If the token is already cancelled, the callback executes immediately.
     *
     * @param callable(): void $callback The function to call on cancellation
     * ```
     */
    public function onCancel(callable $callback): void
    {
        if ($this->cancelled) {
            $callback();

            return;
        }

        $this->cancelCallbacks[] = $callback;
    }

    /**
     * Get the number of promises currently being tracked.
     *
     * Useful for monitoring and debugging to see how many operations are
     * still pending cancellation.
     *
     * @return int Number of tracked promises
     */
    public function getTrackedCount(): int
    {
        return \count($this->trackedPromises);
    }

    /**
     * Clear all tracked promises without cancelling them.
     *
     * This removes all promises from tracking but doesn't cancel them.
     * Useful when you want to stop managing a batch of operations but
     * let them complete naturally.
     */
    public function clearTracked(): void
    {
        $this->trackedPromises = [];
        $this->promiseKeyMap = [];
    }

    /**
     * @param int $promiseId The spl_object_id of the promise
     */
    private function untrackById(int $promiseId): void
    {
        if (isset($this->promiseKeyMap[$promiseId])) {
            $key = $this->promiseKeyMap[$promiseId];
            unset($this->trackedPromises[$key], $this->promiseKeyMap[$promiseId]);
        }
    }
}
