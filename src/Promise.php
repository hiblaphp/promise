<?php

declare(strict_types=1);

namespace Hibla\Promise;

use Hibla\EventLoop\Loop;
use Hibla\Promise\Handlers\ConcurrencyHandler;
use Hibla\Promise\Handlers\PromiseCollectionHandler;
use Hibla\Promise\Handlers\ReduceHandler;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Interfaces\PromiseStaticInterface;

/**
 * A Promise implementation inspired by Promise/A+ with first-class
 * cancellation support. Extends the standard 3-state model with a
 * distinct cancelled state for better resource management.
 *
 * Key Design: 4 States (not 3)
 * - Pending: waiting for resolution
 * - Fulfilled: successfully resolved
 * - Rejected: failed with error
 * - Cancelled: aborted (distinct from rejection)
 *
 * Differences from Promise/A+:
 * - Adds cancelled state (pending/fulfilled/rejected/cancelled)
 * - Cancelled promises don't settle to fulfilled or rejected
 * - Cancelled is neither pending nor a terminal state in the traditional sense
 * - Provides onCancel() for cleanup on cancellation
 *
 * Why not follow Promise/A+ strictly?
 * Promise/A+ defines 3 states. This library adds a 4th (cancelled) because:
 * 1. Cancellation represents user intent to abort, not operational failure
 * 2. Cleanup logic differs from error handling
 * 3. Clearer resource management semantics
 * 4. Matches modern patterns (AbortController concept)
 *
 * This was controversial in JavaScript due to backwards compatibility,
 * but as a new PHP library we can make this opinionated choice.
 *
 * Cancellation Philosophy:
 * - Cancellation itself is NOT an error - it's a normal control flow mechanism
 * - Calling cancel() never throws; it cleanly transitions to cancelled state
 * - However, WAITING on a cancelled promise IS a programming error:
 *   * If you cancel an operation, you've stated you don't want its result
 *   * Attempting to wait() for that result indicates a logic error
 *   * Therefore wait() throws PromiseCancelledException for cancelled promises
 *
 * This design ensures:
 * - Clean cancellation propagation through promise chains
 * - Resources are freed via onCancel() handlers
 * - Programming errors (waiting on cancelled promises) are caught early
 * - Normal cancellation flow remains exception-free
 *
 * -------------------------------------------------------------------------
 * IMPORTANT: What Cancellation Actually Does (and Does NOT Do)
 * -------------------------------------------------------------------------
 *
 * This library uses a **cooperative cancellation model**. This means cancelling a
 * promise is a TWO-LAYER concern. The library signals the intent to cancel,
 * but the underlying task must "cooperate" by providing the logic to stop its
 * own work. Understanding this distinction is critical.
 *
 * Layer 1 — Promise State (what the library does for you):
 * - Signals intent to cancel by transitioning the promise from pending → cancelled.
 * - Prevents any future `then()` or `catch()` callbacks from executing.
 * - Propagates the cancellation signal forward to all child promises in the chain.
 *
 * Layer 2 — Resource Cleanup (what YOU must do via onCancel()):
 * - `cancel()` does NOT magically terminate underlying work (e.g., it will not
 *   interrupt a running `sleep()` or a `curl_exec()` call).
 * - The library has no knowledge of your specific task. Only you know how to
 *   properly close the file handle, abort the HTTP request, or clear the timer.
 * - To release real-world resources, you MUST register the cleanup logic in an
 *   `onCancel()` handler at the point where the asynchronous work is initiated.
 *
 * Example — correct promise construction with resource cleanup:
 *
 * ```php
 *   $promise = new Promise(function($resolve, $reject, $onCancel) {
 *       $timerId = Loop::addTimer(5, fn() => $resolve('done'));
 *       $onCancel(fn() => Loop::cancelTimer($timerId)); // co-located cleanup
 *   });
 * ```
 *
 * Example — incorrect, resource will leak on cancellation:
 * ```php
 *   $promise = new Promise(function($resolve) {
 *       $timerId = Loop::addTimer(5, fn() => $resolve('done'));
 *       // No onCancel() registered — cancelling this promise does nothing
 *       // to the underlying timer. It will still fire after 5 seconds.
 *   });
 * ```
 *
 * This cooperative model mirrors modern async patterns like JavaScript's
 * AbortController, Swift's Task.checkCancellation(), and Kotlin's ensureActive().
 * The promise layer signals intent, but the producer of the value is responsible
 * for acting on that signal.
 *
 * Combinator Behaviour:
 * - Promise::all()        → auto-cancels siblings on first rejection. Callers
 *                           should not expect partial results — register onCancel()
 *                           on each promise for proper resource cleanup.
 * - Promise::race()       → auto-cancels losers when first promise settles.
 * - Promise::any()        → auto-cancels remaining when first promise fulfils.
 * - Promise::allSettled() → never cancels, always waits for every promise.
 *
 * @template-covariant TValue
 *
 * @implements PromiseInterface<TValue>
 */
class Promise implements PromiseInterface, PromiseStaticInterface
{
    /**
     * @var array<PromiseInterface<mixed>>
     */
    protected array $childPromises = [];

    /**
     * @var array<callable>
     */
    private array $thenCallbacks = [];

    /**
     * @var array<callable>
     */
    private array $catchCallbacks = [];

    /**
     * @var array<callable>
     */
    private array $cancelHandlers = [];

    /**
     * @var callable(mixed, PromiseInterface<mixed>): void|null
     */
    private static mixed $rejectionHandler = null;

    /**
     * @var \WeakReference<PromiseInterface<mixed>>|null
     */
    private ?\WeakReference $parentPromise = null;

    private static ?PromiseCollectionHandler $collectionHandler = null;

    private static ?ConcurrencyHandler $concurrencyHandler = null;

    private static ?ReduceHandler $reduceHandler = null;

    /**
     * Internal state backing field. Exposed publicly as $state via property hook.
     */
    private PromiseState $promiseState = PromiseState::PENDING;

    /**
     * Internal value backing field. Exposed publicly as $value via property hook.
     */
    private mixed $promiseValue = null;

    /**
     * Internal reason backing field. Exposed publicly as $reason via property hook.
     */
    private mixed $promiseReason = null;

    private bool $hasRejectionHandler = false;

    private bool $valueAccessed = false;

    /**
     * @inheritDoc
     *
     * @var TValue|null
     */
    public mixed $value {
        get {
            $this->valueAccessed = true;

            return $this->promiseState === PromiseState::FULFILLED
                ? $this->promiseValue
                : null;
        }
    }

    /**
     * @inheritDoc
     *
     * @var mixed
     */
    public mixed $reason {
        get {
            $this->valueAccessed = true;

            return $this->promiseState === PromiseState::REJECTED
                ? $this->promiseReason
                : null;
        }
    }

    /**
     * @inheritDoc
     */
    public string $state {
        get => $this->promiseState->value;
    }

    /**
     * Create a new promise with an optional executor function.
     *
     * The executor function receives resolve, reject, and onCancel callbacks.
     * If no executor is provided, the promise starts in a pending state and
     * can be settled later by calling resolve() or reject() directly (deferred style).
     *
     * @param  callable(
     *     callable(TValue): void,
     *     callable(mixed): void,
     *     callable(callable(): void): void
     * ): void|null  $executor  Function to execute immediately with resolve, reject, and onCancel callbacks
     */
    public function __construct(?callable $executor = null)
    {
        if ($executor !== null) {
            try {
                $executor(
                    fn($value = null) => $this->resolve($value),
                    fn($reason = null) => $this->reject($reason),
                    function (callable $handler): void {
                        $this->onCancel($handler);
                    },
                );
            } catch (\Throwable $e) {
                $this->reject($e);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function wait(): mixed
    {
        $this->throwIfInFiberContext();

        if ($this->promiseState === PromiseState::CANCELLED) {
            throw new Exceptions\CancelledException('Cannot wait on a cancelled promise');
        }

        if ($this->promiseState === PromiseState::FULFILLED) {
            $this->valueAccessed = true;

            return $this->promiseValue;
        }

        if ($this->promiseState === PromiseState::REJECTED) {
            $this->valueAccessed = true;
            $reason = $this->promiseReason;

            throw $reason instanceof \Throwable
                ? $reason
                : new Exceptions\PromiseRejectionException($reason);
        }

        // @phpstan-ignore-next-line identical.alwaysTrue - State changes during Loop::runOnce()
        while ($this->promiseState === PromiseState::PENDING) {
            Loop::runOnce();
        }

        // @phpstan-ignore-next-line deadCode.unreachable - Reachable after event loop execution
        if ($this->promiseState === PromiseState::CANCELLED) {
            throw new Exceptions\CancelledException('Promise was cancelled during wait');
        }

        if ($this->promiseState === PromiseState::REJECTED) {
            $this->valueAccessed = true;
            $reason = $this->promiseReason;

            throw $reason instanceof \Throwable
                ? $reason
                : new Exceptions\PromiseRejectionException($reason);
        }

        $this->valueAccessed = true;

        return $this->promiseValue;
    }

    /**
     * {@inheritdoc}
     */
    public function isSettled(): bool
    {
        return $this->promiseState !== PromiseState::PENDING;
    }

    /**
     * Resolve the promise with a value.
     *
     * If the promise is already settled, this operation has no effect.
     * The resolution triggers all registered fulfillment callbacks.
     *
     * @param  mixed  $value  The value to resolve the promise with
     * @return void
     */
    public function resolve(mixed $value = null): void
    {
        if (! $this->canSettle()) {
            return;
        }

        if ($value === $this) {
            $this->reject(new \TypeError('Chaining cycle detected'));

            return;
        }

        if ($value instanceof PromiseInterface) {
            $value->then(
                fn($v) => $this->resolve($v),
                fn($r) => $this->reject($r)
            );

            // If THIS promise is cancelled, forward it to the inner promise
            // we are waiting on. This bridges the gap between layers.
            $this->onCancel(function () use ($value) {
                if (! $value->isSettled()) {
                    $value->cancel();
                }
            });

            return;
        }

        if (\is_object($value) && method_exists($value, 'then')) {
            try {
                $value->then(
                    $this->resolve(...),
                    $this->reject(...),
                );
            } catch (\Throwable $e) {
                $this->reject($e);
            }

            return;
        }

        $this->promiseState = PromiseState::FULFILLED;
        $this->promiseValue = $value;

        Loop::microTask(function () use ($value) {
            if ($this->promiseState === PromiseState::CANCELLED) {
                return;
            }

            $callbacks = $this->thenCallbacks;
            $this->thenCallbacks = [];

            foreach ($callbacks as $callback) {
                $callback($value);
            }

            $this->cleanup();
        });
    }

    /**
     * Reject the promise with a reason.
     *
     * If the promise is already settled, this operation has no effect.
     * The rejection triggers all registered rejection callbacks.
     *
     * @param  mixed  $reason  The reason for rejection (typically an exception)
     * @return void
     */
    public function reject(mixed $reason): void
    {
        if (! $this->canSettle()) {
            return;
        }

        $this->promiseState = PromiseState::REJECTED;
        $this->promiseReason = $reason;

        Loop::microTask(function () {
            if ($this->promiseState === PromiseState::CANCELLED) {
                return;
            }

            $callbacks = $this->catchCallbacks;
            $this->catchCallbacks = [];

            foreach ($callbacks as $callback) {
                $callback($this->promiseReason);
            }

            $this->cleanup();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function cancelChain(): void
    {
        $current = $this;

        while (true) {
            $weakParent = $current->parentPromise;
            $parent = $weakParent?->get();

            if (! $parent instanceof Promise) {
                break;
            }

            if ($parent->isSettled()) {
                break;
            }

            $current = $parent;
        }

        if (! $current->isSettled()) {
            $current->cancel();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function cancel(): void
    {
        if ($this->promiseState !== PromiseState::PENDING) {
            return;
        }

        $this->promiseState = PromiseState::CANCELLED;
        $this->thenCallbacks = [];
        $this->catchCallbacks = [];

        $cancelExceptions = [];

        foreach ($this->cancelHandlers as $handler) {
            try {
                $handler();
            } catch (\Throwable $e) {
                $cancelExceptions[] = $e;
            }
        }

        $this->cancelHandlers = [];

        try {
            $this->cancelChildren();
        } catch (\Throwable $e) {
            $cancelExceptions[] = $e;
        }

        $this->cleanup();

        $count = \count($cancelExceptions);

        if ($count === 1) {
            throw $cancelExceptions[0];
        } elseif ($count > 1) {
            $errorMessages = [];
            foreach ($cancelExceptions as $index => $exception) {
                $errorMessages[] = \sprintf(
                    '#%d: [%s] %s in %s:%d',
                    $index + 1,
                    \get_class($exception),
                    $exception->getMessage(),
                    $exception->getFile(),
                    $exception->getLine()
                );
            }

            $detailedMessage = \sprintf(
                "Promise cancellation failed with %d error(s):\n%s",
                $count,
                implode("\n", $errorMessages)
            );

            throw new Exceptions\AggregateErrorException(
                $cancelExceptions,
                $detailedMessage
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isCancelled(): bool
    {
        return $this->promiseState === PromiseState::CANCELLED;
    }

    /**
     * {@inheritdoc}
     */
    public function onCancel(callable $handler): PromiseInterface
    {
        if ($this->promiseState === PromiseState::CANCELLED) {
            $handler();

            return $this;
        }

        $this->cancelHandlers[] = $handler;

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @template TResult
     *
     * @param  callable(TValue): (TResult|PromiseInterface<TResult>)|null  $onFulfilled
     * @param  callable(\Throwable): (TResult|PromiseInterface<TResult>)|null  $onRejected
     * @return PromiseInterface<TResult>
     */
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): PromiseInterface
    {
        if ($onRejected !== null || $onFulfilled !== null) {
            $this->hasRejectionHandler = true;
        }

        /** @var Promise<TResult>|null $newPromise */
        $newPromise = null;

        // Create a WeakReference to $this. This prevents the closures
        // below from creating a strong reference cycle that leaks memory.
        $weakSelf = \WeakReference::create($this);

        $executor = function (callable $resolve, callable $reject) use ($onFulfilled, $onRejected, &$newPromise, $weakSelf) {
            // Use a static function to prevent implicit capture of $this
            $handleResolve = static function ($value) use ($onFulfilled, $resolve, $reject, &$newPromise, $weakSelf) {
                // Unwrap the parent promise from the weak reference
                $self = $weakSelf->get();

                // If parent has been garbage collected or was cancelled, abort
                if ($self === null || $self->promiseState === PromiseState::CANCELLED) {
                    return;
                }

                assert($newPromise instanceof PromiseInterface);
                if ($newPromise->isCancelled()) {
                    return;
                }

                if ($onFulfilled !== null) {
                    try {
                        $result = $onFulfilled($value);
                        $resolve($result);
                    } catch (\Throwable $e) {
                        $reject($e);
                    }
                } else {
                    $resolve($value);
                }
            };

            // Use a static function to prevent implicit capture of $this
            $handleReject = static function (mixed $reason) use ($onRejected, $resolve, $reject, &$newPromise, $weakSelf) {
                $self = $weakSelf->get();

                if ($self === null || $self->promiseState === PromiseState::CANCELLED) {
                    return;
                }

                assert($newPromise instanceof PromiseInterface);
                if ($newPromise->isCancelled()) {
                    return;
                }

                if ($onRejected !== null) {
                    try {
                        /** @phpstan-ignore argument.type (The code callback is preserve to mixed rather than throwable to get the orginal reason)*/
                        $result = $onRejected($reason);
                        $resolve($result);
                    } catch (\Throwable $e) {
                        $reject($e);
                    }
                } else {
                    $reject($reason);
                }
            };

            if ($this->promiseState === PromiseState::CANCELLED) {
                return;
            }

            if ($this->promiseState === PromiseState::FULFILLED) {
                Loop::microTask(fn() => $handleResolve($this->promiseValue));
            } elseif ($this->promiseState === PromiseState::REJECTED) {
                Loop::microTask(fn() => $handleReject($this->promiseReason));
            } else {
                $this->thenCallbacks[] = $handleResolve;
                $this->catchCallbacks[] = $handleReject;
            }
        };

        /** @var Promise<TResult> $newPromise */
        $newPromise = new self($executor);
        // Use WeakReference for parent to prevent child->parent cycle
        $newPromise->parentPromise = \WeakReference::create($this);
        $this->childPromises[] = $newPromise;

        if ($this->promiseState === PromiseState::CANCELLED) {
            $newPromise->cancel();
        }

        return $newPromise;
    }

    /**
     * {@inheritdoc}
     *
     * @template TRejected
     *
     * @param  callable(\Throwable): (TRejected|PromiseInterface<TRejected>)  $onRejected
     * @return PromiseInterface<TValue|TRejected>
     */
    public function catch(callable $onRejected): PromiseInterface
    {
        $this->hasRejectionHandler = true;

        /** @var callable(\Throwable): (TRejected|PromiseInterface<TRejected>) $handler */
        $handler = $onRejected;

        return $this->then(null, $handler);
    }

    /**
     * {@inheritdoc}
     *
     * @param callable(): (void|PromiseInterface<void>) $onFinally Callback to execute on any outcome
     * @return PromiseInterface<TValue>
     */
    public function finally(callable $onFinally): PromiseInterface
    {
        $this->onCancel($onFinally);

        return $this->then(
            static function ($value) use ($onFinally): mixed {
                $result = $onFinally();

                if ($result instanceof PromiseInterface) {
                    return $result->then(fn (): mixed => $value);
                }

                return $value;
            },
            static function (\Throwable $reason) use ($onFinally): mixed {
                $result = $onFinally();

                if ($result instanceof PromiseInterface) {
                    return $result->then(static function () use ($reason): never {
                        throw $reason;
                    });
                }

                throw $reason;
            }
        );
    }

    /**
     * {@inheritdoc}
     */
    public function isFulfilled(): bool
    {
        return $this->promiseState === PromiseState::FULFILLED;
    }

    /**
     * {@inheritdoc}
     */
    public function isRejected(): bool
    {
        return $this->promiseState === PromiseState::REJECTED;
    }

    /**
     * {@inheritdoc}
     */
    public function isPending(): bool
    {
        return $this->promiseState === PromiseState::PENDING;
    }

    /**
     * @inheritDoc
     * @template TResolveValue
     * @param  TResolveValue  $value  The value to resolve the promise with
     *
     * @return PromiseInterface<TResolveValue> A promise resolved with the provided value
     */
    public static function resolved(mixed $value = null): PromiseInterface
    {
        /** @var Promise<TResolveValue> $promise */
        $promise = new self();
        $promise->promiseState = PromiseState::FULFILLED;
        $promise->promiseValue = $value;

        return $promise;
    }

    /**
     * @inheritDoc
     *
     * @return PromiseInterface<never> A promise rejected with the provided reason
     */
    public static function rejected(mixed $reason): PromiseInterface
    {
        /** @var Promise<never> $promise */
        $promise = new self();
        $promise->promiseState = PromiseState::REJECTED;
        $promise->promiseReason = $reason;

        return $promise;
    }

    /**
     * @inheritDoc
     */
    public static function all(iterable $promises): PromiseInterface
    {
        return self::getCollectionHandler()->all($promises);
    }

    /**
     * @inheritDoc
     */
    public static function allSettled(iterable $promises): PromiseInterface
    {
        return self::getCollectionHandler()->allSettled($promises);
    }

    /**
     * @inheritDoc
     */
    public static function race(iterable $promises): PromiseInterface
    {
        return self::getCollectionHandler()->race($promises);
    }

    /**
     * @inheritDoc
     */
    public static function any(iterable $promises): PromiseInterface
    {
        return self::getCollectionHandler()->any($promises);
    }

    /**
     * @inheritDoc
     */
    public static function timeout(PromiseInterface $promise, float $seconds): PromiseInterface
    {
        return self::getCollectionHandler()->timeout($promise, $seconds);
    }

    /**
     * @inheritDoc
     */
    public static function concurrent(iterable $tasks, int $concurrency = 10): PromiseInterface
    {
        return self::getConcurrencyHandler()->concurrent($tasks, $concurrency);
    }

    /**
     * @inheritDoc
     */
    public static function batch(iterable $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface
    {
        return self::getConcurrencyHandler()->batch($tasks, $batchSize, $concurrency);
    }

    /**
     * @inheritDoc
     */
    public static function concurrentSettled(iterable $tasks, int $concurrency = 10): PromiseInterface
    {
        return self::getConcurrencyHandler()->concurrentSettled($tasks, $concurrency);
    }

    /**
     * @inheritDoc
     */
    public static function batchSettled(iterable $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface
    {
        return self::getConcurrencyHandler()->batchSettled($tasks, $batchSize, $concurrency);
    }

    /**
     * @inheritDoc
     */
    public static function map(iterable $items, callable $mapper, ?int $concurrency = null): PromiseInterface
    {
        return self::getConcurrencyHandler()->map($items, $mapper, $concurrency);
    }

    /**
     * @inheritDoc
     */
    public static function mapSettled(iterable $items, callable $mapper, ?int $concurrency = null): PromiseInterface
    {
        return self::getConcurrencyHandler()->mapSettled($items, $mapper, $concurrency);
    }

    /**
     * @inheritDoc
     */
    public static function filter(iterable $items, callable $predicate, ?int $concurrency = null): PromiseInterface
    {
        return self::getConcurrencyHandler()->filter($items, $predicate, $concurrency);
    }

    /**
     * @inheritDoc
     */
    public static function reduce(iterable $items, callable $reducer, mixed $initial = null): PromiseInterface
    {
        return self::getReduceHandler()->reduce($items, $reducer, $initial);
    }

    /**
     * @inheritDoc
     */
    public static function forEach(iterable $items, callable $callback, ?int $concurrency = null): PromiseInterface
    {
        return self::getConcurrencyHandler()->forEach($items, $callback, $concurrency);
    }

    /**
     * @inheritDoc
     */
    public static function forEachSettled(iterable $items, callable $callback, ?int $concurrency = null): PromiseInterface
    {
        return self::getConcurrencyHandler()->forEachSettled($items, $callback, $concurrency);
    }

    /**
     * Set a global handler for unhandled promise rejections.
     *
     * Called in __destruct() when a rejected promise is garbage collected
     * without a rejection handler having been attached.
     *
     * Pass null to restore the default stderr behaviour.
     * Returns the previously registered handler (or null if none was set),
     * mirroring the convention of PHP's set_error_handler().
     *
     * @param  callable(mixed $reason, PromiseInterface<mixed> $promise): void|null  $handler
     * @return callable(mixed $reason, PromiseInterface<mixed> $promise): void|null
     */
    public static function setRejectionHandler(?callable $handler): ?callable
    {
        $previous = self::$rejectionHandler;
        self::$rejectionHandler = $handler;

        return $previous;
    }

    /**
     * Get or create the PromiseCollectionHandler instance.
     */
    private static function getCollectionHandler(): PromiseCollectionHandler
    {
        return self::$collectionHandler ??= new PromiseCollectionHandler();
    }

    /**
     * Get or create the ConcurrencyHandler instance.
     */
    private static function getConcurrencyHandler(): ConcurrencyHandler
    {
        return self::$concurrencyHandler ??= new ConcurrencyHandler();
    }

    /**
     * Get or create the ReduceHandler instance.
     */
    private static function getReduceHandler(): ReduceHandler
    {
        return self::$reduceHandler ??= new ReduceHandler();
    }

    /**
     * Throw an exception if the method is called from within a Fiber context.
     *
     * @return void
     */
    private function throwIfInFiberContext(): void
    {
        if (\Fiber::getCurrent() !== null) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

            $userCaller = null;
            foreach ($backtrace as $frame) {
                $file = $frame['file'] ?? '';

                if (
                    $file !== '' &&
                    ! str_contains($file, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) &&
                    ! str_contains($file, DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Handlers' . DIRECTORY_SEPARATOR)
                ) {
                    $userCaller = $frame;

                    break;
                }
            }

            $caller = $userCaller ?? $backtrace[1] ?? $backtrace[0];
            $file = str_replace(getcwd() . DIRECTORY_SEPARATOR, '', $caller['file'] ?? 'unknown');
            $line = $caller['line'] ?? 'unknown';

            throw new Exceptions\InvalidContextException(
                "Cannot call wait() inside a Fiber context.\n" .
                    "  Location: {$file}:{$line}\n" .
                    "  Problem: Calling wait() blocks the fiber and prevents event loop processing.\n" .
                    '  Solution: Use Hibla\\await() instead to properly suspend the fiber.'
            );
        }
    }

    /**
     * Clean up circular references to prevent memory leaks.
     *
     * @return void
     */
    private function cleanup(): void
    {
        $this->parentPromise = null;
        $this->childPromises = [];
        $this->thenCallbacks = [];
        $this->catchCallbacks = [];
        $this->cancelHandlers = [];
    }

    /**
     * Check if the Promise can be settled (resolved or rejected).
     *
     * @return bool True if the Promise can be settled, false if already settled or cancelled
     */
    private function canSettle(): bool
    {
        return $this->promiseState === PromiseState::PENDING;
    }

    /**
     * Cancel all child promises (forward propagation).
     * This is called when a parent promise is cancelled.
     *
     * @return void
     */
    private function cancelChildren(): void
    {
        $childExceptions = [];

        foreach ($this->childPromises as $child) {
            if (! $child->isCancelled()) {
                try {
                    $child->cancel();
                } catch (\Throwable $e) {
                    $childExceptions[] = $e;
                }
            }
        }

        $this->childPromises = [];

        $count = \count($childExceptions);

        if ($count === 1) {
            throw $childExceptions[0];
        } elseif ($count > 1) {
            throw new Exceptions\AggregateErrorException(
                $childExceptions,
                \sprintf('Multiple errors during child promise cancellation: %d error(s)', $count)
            );
        }
    }

    /**
     * Safely convert mixed value to string for error messages.
     */
    private function safeStringCast(mixed $value): string
    {
        return match (true) {
            \is_string($value) => $value,
            \is_null($value) => 'null',
            \is_scalar($value) => (string) $value,
            \is_object($value) && method_exists($value, '__toString') => (string) $value,
            \is_array($value) => 'Array: ' . json_encode($value),
            \is_object($value) => 'Object: ' . get_class($value),
            default => 'Unknown error type: ' . gettype($value)
        };
    }

    public function __destruct()
    {
        if ($this->valueAccessed) {
            return;
        }

        if ($this->promiseState === PromiseState::REJECTED && ! $this->hasRejectionHandler) {
            if (self::$rejectionHandler !== null) {
                (self::$rejectionHandler)($this->promiseReason, $this);

                return;
            }

            if ($this->promiseReason instanceof \Throwable) {
                throw $this->promiseReason;
            }

            throw new \Exception(
                $this->safeStringCast($this->promiseReason)
            );
        }
    }
}
