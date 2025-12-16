<?php

declare(strict_types=1);

namespace Hibla\Promise;

use function Hibla\delay;

use Hibla\Promise\Exceptions\PromiseCancelledException;
use Hibla\Promise\Interfaces\PromiseInterface;

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
     * Create a linked cancellation token that will be cancelled when ANY of the source tokens are cancelled.
     *
     * This allows combining multiple cancellation sources (user cancellation, timeout, shutdown, etc.)
     * into a single token. When any source token cancels, the linked token automatically cancels.
     *
     * @param CancellationToken ...$sources One or more source tokens to link
     * @return self A new token that cancels when any source cancels
     *
     * ```php
     * $userToken = new CancellationToken();
     * $timeoutToken = new CancellationToken();
     * $timeoutToken->cancelAfter(5.0);
     *
     * // Cancels if user cancels OR timeout expires
     * $linkedToken = CancellationToken::linked($userToken, $timeoutToken);
     *
     * $result = await($promise, $linkedToken);
     * ```
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

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

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
     * @template TValue
     * @param PromiseInterface<TValue> $promise
     * @return PromiseInterface<TValue>
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
        $promiseId = spl_object_id($promise);

        $promise->finally(static function () use ($weakThis, $promiseId): void {
            $token = $weakThis->get();
            if ($token !== null) {
                $token->untrackById($promiseId);
            }
        });

        return $promise;
    }

    /**
     * @param PromiseInterface<mixed> $promise
     */
    public function untrack(PromiseInterface $promise): void
    {
        $this->untrackById(spl_object_id($promise));
    }

    public function cancelAfter(float $seconds): void
    {
        delay($seconds)->then(function () {
            $this->cancel();
        });
    }

    public function throwIfCancelled(): void
    {
        if ($this->cancelled) {
            throw new PromiseCancelledException('Operation was cancelled');
        }
    }

    public function onCancel(callable $callback): void
    {
        if ($this->cancelled) {
            $callback();

            return;
        }

        $this->cancelCallbacks[] = $callback;
    }

    public function getTrackedCount(): int
    {
        return \count($this->trackedPromises);
    }

    public function clearTracked(): void
    {
        $this->trackedPromises = [];
        $this->promiseKeyMap = [];
    }

    private function untrackById(int $promiseId): void
    {
        if (isset($this->promiseKeyMap[$promiseId])) {
            $key = $this->promiseKeyMap[$promiseId];
            unset($this->trackedPromises[$key], $this->promiseKeyMap[$promiseId]);
        }
    }
}
