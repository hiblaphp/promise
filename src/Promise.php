<?php

declare(strict_types=1);

namespace Hibla\Promise;

use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\PromiseRejectionException;
use Hibla\Promise\Handlers\ConcurrencyHandler;
use Hibla\Promise\Handlers\PromiseCollectionHandler;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Interfaces\PromiseStaticInterface;

/**
 * A Promise implementation inspired by Promise/A+ with first-class
 * cancellation support. Extends the standard 3-state model with a
 * distinct cancelled state for better resource management.
 *
 * This library implements the "third state" approach to cancellation that
 * was debated (but ultimately withdrawn) in TC39's cancelable promises
 * proposal. The library believe this approach provides better semantics for PHP:
 *
 *  Key Design: 4 States (not 3)
 * - Pending: waiting for resolution
 * - Fulfilled: successfully resolved
 * - Rejected: failed with error
 * - Cancelled: aborted (distinct from rejection)
 *
 * Differences from Promise/A+:
 * - Adds cancelled state (pending/fulfilled/rejected/cancelled)
 * - Cancelled promises don't settle to fulfilled or rejected nor it is a pending state anymore.
 * - Provides onCancel() for cleanup or execution on cancellation
 *
 * Why not follow Promise/A+ strictly?
 * Promise/A+ defines 3 states. This Library add a 4th (cancelled) because:
 * 1. Cancellation is not a rejection - it's user intent
 * 2. Cleanup logic differs from rejection handling
 * 3. Clearer resource management
 * 4. Matches modern patterns (AbortController concept)
 *
 * This was controversial in JavaScript due to backwards compatibility,
 * but as a new PHP library we can make this opinionated choice.
 *
 * @template TValue
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
     * @var PromiseInterface<mixed>|null
     */
    private ?PromiseInterface $parentPromise = null;

    private static ?PromiseCollectionHandler $collectionHandler = null;

    private static ?ConcurrencyHandler $concurrencyHandler = null;

    private PromiseState $state = PromiseState::PENDING;

    private mixed $value = null;

    private mixed $reason = null;

    private bool $hasRejectionHandler = false;

    private bool $valueAccessed = false;

    /**
     * Create a new promise with an optional executor function.
     *
     * The executor function receives resolve and reject callbacks that
     * can be used to settle the promise. If no executor is provided,
     * the promise starts in a pending state.
     *
     * @param  callable(callable(TValue): void, callable(mixed): void): void|null  $executor  Function to execute immediately with resolve/reject callbacks
     */
    public function __construct(?callable $executor = null)
    {
        if ($executor !== null) {
            try {
                $executor(
                    fn ($value = null) => $this->resolve($value),
                    fn ($reason = null) => $this->reject($reason)
                );
            } catch (\Throwable $e) {
                $this->reject($e);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function wait(bool $resetEventLoop = false): mixed
    {
        try {
            if ($this->state === PromiseState::CANCELLED) {
                throw new Exceptions\PromiseCancelledException('Cannot wait on a cancelled promise');
            }

            if ($this->state === PromiseState::FULFILLED) {
                $this->valueAccessed = true;

                return $this->value;
            }

            if ($this->state === PromiseState::REJECTED) {
                $this->valueAccessed = true;
                $reason = $this->reason;

                throw $reason instanceof \Throwable
                    ? $reason
                    : new \Exception($this->safeStringCast($reason));
            }

            $result = null;
            $error = null;

            $this
                ->then(function ($value) use (&$result) {
                    $result = $value;

                    return $value;
                })
                ->catch(function ($reason) use (&$error) {
                    $error = $reason;

                    return $reason;
                })
            ;

            Loop::run();

            // @phpstan-ignore-next-line promise state can change in runtime
            if ($this->state === PromiseState::CANCELLED) {
                throw new Exceptions\PromiseCancelledException('Promise was cancelled during wait');
            }

            if ($error !== null) {
                throw $error instanceof \Throwable
                    ? $error
                    : new \Exception($this->safeStringCast($error));
            }

            return $result;
        } finally {
            if ($resetEventLoop) {
                Loop::reset();
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isSettled(): bool
    {
        return $this->state === PromiseState::FULFILLED || $this->state === PromiseState::REJECTED;
    }

    public function resolve(mixed $value): void
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
                fn ($v) => $this->resolve($v),
                fn ($r) => $this->reject($r)
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
                // @phpstan-ignore-next-line this is valid call to ensure it calls thenable method from other class or libraries
                $value->then(
                    fn ($v) => $this->resolve($v),
                    fn ($r) => $this->reject($r)
                );
            } catch (\Throwable $e) {
                $this->reject($e);
            }

            return;
        }

        $this->state = PromiseState::FULFILLED;
        $this->value = $value;

        Loop::microTask(function () use ($value) {
            $callbacks = $this->thenCallbacks;
            $this->thenCallbacks = [];

            foreach ($callbacks as $callback) {
                $callback($value);
            }
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

        $this->state = PromiseState::REJECTED;

        $this->reason = $reason instanceof \Throwable
            ? $reason
            : new PromiseRejectionException($reason);

        Loop::microTask(function () {
            $callbacks = $this->catchCallbacks;
            $this->catchCallbacks = [];

            foreach ($callbacks as $callback) {
                $callback($this->reason);
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function cancelChain(): void
    {
        $current = $this;

        while ($current->parentPromise !== null && ! $current->parentPromise->isCancelled()) {
            assert($current->parentPromise instanceof Promise);
            $current = $current->parentPromise;
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
        if ($this->state !== PromiseState::PENDING) {
            return;
        }

        $this->state = PromiseState::CANCELLED;
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

        if (\count($cancelExceptions) === 1) {
            throw $cancelExceptions[0];
        } elseif (\count($cancelExceptions) > 1) {
            throw new Exceptions\AggregateErrorException(
                $cancelExceptions,
                \sprintf('Promise cancellation failed with %d error(s)', \count($cancelExceptions))
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isCancelled(): bool
    {
        return $this->state === PromiseState::CANCELLED;
    }

    /**
     * @inheritdoc
     */
    public function onCancel(callable $handler): PromiseInterface
    {
        if ($this->state === PromiseState::CANCELLED) {
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
     * @param  callable(mixed): (TResult|PromiseInterface<TResult>)|null  $onRejected
     * @return PromiseInterface<TResult>
     */
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): PromiseInterface
    {
        if ($onRejected !== null || $onFulfilled !== null) {
            $this->hasRejectionHandler = true;
        }

        /** @var Promise<TResult>|null $newPromise */
        $newPromise = null;

        $executor = function (callable $resolve, callable $reject) use ($onFulfilled, $onRejected, &$newPromise) {
            $handleResolve = function ($value) use ($onFulfilled, $resolve, $reject, &$newPromise) {
                if ($this->state === PromiseState::CANCELLED) {
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

            $handleReject = function ($reason) use ($onRejected, $resolve, $reject, &$newPromise) {
                if ($this->state === PromiseState::CANCELLED) {
                    return;
                }

                assert($newPromise instanceof PromiseInterface);
                if ($newPromise->isCancelled()) {
                    return;
                }

                if ($onRejected !== null) {
                    try {
                        $result = $onRejected($reason);
                        $resolve($result);
                    } catch (\Throwable $e) {
                        $reject($e);
                    }
                } else {
                    $reject($reason);
                }
            };

            if ($this->state === PromiseState::CANCELLED) {
                return;
            }

            if ($this->state === PromiseState::FULFILLED) {
                Loop::microTask(fn () => $handleResolve($this->value));
            } elseif ($this->state === PromiseState::REJECTED) {
                Loop::microTask(fn () => $handleReject($this->reason));
            } else {
                $this->thenCallbacks[] = $handleResolve;
                $this->catchCallbacks[] = $handleReject;
            }
        };

        /** @var Promise<TResult> $newPromise */
        $newPromise = new self($executor);
        $newPromise->parentPromise = $this;
        $this->childPromises[] = $newPromise;

        if ($this->state === PromiseState::CANCELLED) {
            $newPromise->cancel();
        }

        return $newPromise;
    }

    /**
     * {@inheritdoc}
     *
     * @template TResult
     *
     * @param  callable(mixed): (TResult|PromiseInterface<TResult>)  $onRejected
     * @return PromiseInterface<TResult>
     */
    public function catch(callable $onRejected): PromiseInterface
    {
        $this->hasRejectionHandler = true;

        return $this->then(null, $onRejected);
    }

    /**
     * {@inheritdoc}
     *
     * @param callable $onFinally Callback to execute on any outcome
     * @return PromiseInterface<TValue>
     */
    public function finally(callable $onFinally): PromiseInterface
    {
        $this->onCancel($onFinally);

        return $this->then(
            function ($value) use ($onFinally) {
                $result = $onFinally();

                return (new self(fn ($resolve) => $resolve($result)))
                    ->then(fn () => $value)
                ;
            },
            function ($reason) use ($onFinally): PromiseInterface {
                $result = $onFinally();

                return (new self(fn ($resolve) => $resolve($result)))
                    ->then(function () use ($reason): void {
                        if ($reason instanceof \Throwable) {
                            throw $reason;
                        }

                        throw new PromiseRejectionException($this->safeStringCast($reason));
                    })
                ;
            }
        );
    }

    /**
     * {@inheritdoc}
     */
    public function isFulfilled(): bool
    {
        return $this->state === PromiseState::FULFILLED;
    }

    /**
     * {@inheritdoc}
     */
    public function isRejected(): bool
    {
        return $this->state === PromiseState::REJECTED;
    }

    /**
     * {@inheritdoc}
     */
    public function isPending(): bool
    {
        return $this->state === PromiseState::PENDING;
    }

    /**
     * {@inheritdoc}
     */
    public function getValue(): mixed
    {
        $this->valueAccessed = true;

        if ($this->state !== PromiseState::FULFILLED) {
            return null;
        }

        return $this->value;
    }

    /**
     * {@inheritdoc}
     */
    public function getReason(): mixed
    {
        $this->valueAccessed = true;

        if ($this->state !== PromiseState::REJECTED) {
            return null;
        }

        return $this->reason;
    }

    /**
     * {@inheritdoc}
     */
    public function getState(): string
    {
        return $this->state->value;
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
        $promise->state = PromiseState::FULFILLED;
        $promise->value = $value;

        return $promise;
    }

    /**
     * @inheritDoc
     * @param  mixed  $reason  The reason for rejection (typically an exception)
     *
     * @return PromiseInterface<mixed> A promise rejected with the provided reason
     */
    public static function rejected(mixed $reason): PromiseInterface
    {
        $promise = new self();

        $promise->state = PromiseState::REJECTED;
        $promise->reason = $reason instanceof \Throwable
            ? $reason
            : new PromiseRejectionException($reason);

        return $promise;
    }

    /**
     * @inheritDoc
     * @template TAllValue
     * @param  array<int|string, PromiseInterface<TAllValue>>  $promises  Array of PromiseInterface instances.
     * @return PromiseInterface<array<int|string, TAllValue>> A promise that resolves with an array of results.
     */
    public static function all(array $promises): PromiseInterface
    {
        return self::getCollectionHandler()->all($promises);
    }

    /**
     * @inheritDoc
     * @template TAllSettledValue
     * @param  array<int|string, PromiseInterface<TAllSettledValue>>  $promises
     * @return PromiseInterface<array<int|string, array{status: 'fulfilled'|'rejected', value?: TAllSettledValue, reason?: mixed}>>
     */
    public static function allSettled(array $promises): PromiseInterface
    {
        return self::getCollectionHandler()->allSettled($promises);
    }

    /**
     * @inheritDoc
     * @template TRaceValue
     * @param  array<int|string, PromiseInterface<TRaceValue>>  $promises  Array of PromiseInterface instances.
     * @return PromiseInterface<TRaceValue> A promise that settles with the first settled promise.
     */
    public static function race(array $promises): PromiseInterface
    {
        return self::getCollectionHandler()->race($promises);
    }

    /**
     * @inheritDoc
     * @template TAnyValue
     * @param  array<int|string, PromiseInterface<TAnyValue>>  $promises  Array of promises to wait for
     * @return PromiseInterface<TAnyValue> A promise that resolves with the first settled value
     */
    public static function any(array $promises): PromiseInterface
    {
        return self::getCollectionHandler()->any($promises);
    }

    /**
     * @inheritDoc
     * @template TTimeoutValue
     * @param  PromiseInterface<TTimeoutValue>  $promise  The promise to add timeout to
     * @param  float  $seconds  Timeout duration in seconds
     * @return PromiseInterface<TTimeoutValue>
     */
    public static function timeout(PromiseInterface $promise, float $seconds): PromiseInterface
    {
        return self::getCollectionHandler()->timeout($promise, $seconds);
    }

    /**
     * @inheritDoc
     * @template TConcurrentValue
     * @param  array<int|string, callable(): PromiseInterface<TConcurrentValue>>  $tasks  Array of callable tasks that return promises. Must be callables for proper concurrency control.
     * @param  int  $concurrency  Maximum number of concurrent executions (default: 10).
     * @return PromiseInterface<array<int|string, TConcurrentValue>> A promise that resolves with an array of all results.
     */
    public static function concurrent(array $tasks, int $concurrency = 10): PromiseInterface
    {
        return self::getConcurrencyHandler()->concurrent($tasks, $concurrency);
    }

    /**
     * @inheritDoc
     * @template TBatchValue
     * @param  array<int|string, callable(): PromiseInterface<TBatchValue>>  $tasks  Array of tasks that return promises. Must be callables for proper concurrency control.
     * @param  int  $batchSize  Size of each batch to process concurrently.
     * @param  int|null  $concurrency  Maximum number of concurrent executions per batch.
     * @return PromiseInterface<array<int|string, TBatchValue>> A promise that resolves with all results.
     */
    public static function batch(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface
    {
        return self::getConcurrencyHandler()->batch($tasks, $batchSize, $concurrency);
    }

    /**
     * @inheritDoc
     * @template TConcurrentSettledValue
     * @param  array<int|string, callable(): PromiseInterface<TConcurrentSettledValue>>  $tasks  Array of tasks that return promises. Must be callables for proper concurrency control.
     * @param  int  $concurrency  Maximum number of concurrent executions
     * @return PromiseInterface<array<int|string, array{status: 'fulfilled'|'rejected', value?: TConcurrentSettledValue, reason?: mixed}>> A promise that resolves with settlement results
     */
    public static function concurrentSettled(array $tasks, int $concurrency = 10): PromiseInterface
    {
        return self::getConcurrencyHandler()->concurrentSettled($tasks, $concurrency);
    }

    /**
     * @inheritDoc
     * @template TBatchSettledValue
     * @param  array<int|string, callable(): PromiseInterface<TBatchSettledValue>>  $tasks  Array of tasks that return promises. Must be callables for proper concurrency control.
     * @param  int  $batchSize  Size of each batch to process concurrently
     * @param  int|null  $concurrency  Maximum number of concurrent executions per batch
     * @return PromiseInterface<array<int|string, array{status: 'fulfilled'|'rejected', value?: TBatchSettledValue, reason?: mixed}>> A promise that resolves with settlement results
     */
    public static function batchSettled(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface
    {
        return self::getConcurrencyHandler()->batchSettled($tasks, $batchSize, $concurrency);
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
     * Check if the Promise can be settled (resolved or rejected).
     *
     * @return bool True if the Promise can be settled, false if already settled or cancelled
     */
    private function canSettle(): bool
    {
        return $this->state === PromiseState::PENDING;
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

        if (! empty($childExceptions)) {
            throw $childExceptions[0];
        }
    }

    /**
     * Safely convert mixed value to string for error messages
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

        if ($this->state === PromiseState::REJECTED && ! $this->hasRejectionHandler) {
            $reason = $this->reason;
            $message = $reason instanceof \Throwable
                ? \sprintf(
                    "Unhandled promise rejection with %s: %s in %s:%d\nStack trace:\n%s",
                    \get_class($reason),
                    $reason->getMessage(),
                    $reason->getFile(),
                    $reason->getLine(),
                    $reason->getTraceAsString()
                )
                : 'Unhandled promise rejection: ' . print_r($reason, true);

            fwrite(STDERR, $message . PHP_EOL);
        }
    }
}
