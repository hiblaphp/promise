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
 * Differences from Promise/A+:
 * - Adds cancelled state (pending/fulfilled/rejected/cancelled)
 * - Cancelled promises don't settle to rejected
 * - Provides setCancelHandler() for cleanup or execution on cancellation
 *
 *
 * @template TValue
 *
 * @implements PromiseInterface<TValue>
 */
class Promise implements PromiseStaticInterface, PromiseInterface
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
    private bool $resolved = false;
    private bool $rejected = false;
    private bool $cancelled = false;
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
                    fn($value = null) => $this->resolve($value),
                    fn($reason = null) => $this->reject($reason)
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
            if ($this->resolved) {
                return $this->value;
            }

            if ($this->rejected) {
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
                });

            Loop::run();

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
        return $this->resolved || $this->rejected;
    }

    /**
     * Resolve the promise with a value.
     *
     * If the promise is already settled, this operation has no effect.
     * The resolution triggers all registered fulfillment callbacks.
     *
     * @param  TValue  $value  The value to resolve the promise with
     * @return void
     */
    public function resolve(mixed $value): void
    {
        if (! $this->canSettle()) {
            return;
        }

        $this->resolved = true;
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

        $this->rejected = true;

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

        $current->cancel();
    }

    /**
     * {@inheritdoc}
     */
    public function cancel(): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;
        $this->resolved = false;
        $this->rejected = false;
        $this->thenCallbacks = [];
        $this->catchCallbacks = [];

        foreach (array_reverse($this->cancelHandlers) as $handler) {
            $handler();
        }
        $this->cancelHandlers = [];

        $this->cancelChildren();
    }

    /**
     * {@inheritdoc}
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * @inheritdoc
     */
    public function onCancel(callable $handler): PromiseInterface
    {
        if ($this->cancelled) {
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
    /**
     * {@inheritdoc}
     *
     * @template TResult
     *
     * @param  callable(TValue): (TResult|PromiseInterface<TResult>)|null  $onFulfilled
     * @param  callable(mixed): (TResult|PromiseInterface<TResult>)|null  $onRejected
     * @return PromiseInterface<TResult>
     */
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
                if ($this->isCancelled()) {

                    return;
                }

                assert($newPromise instanceof PromiseInterface);
                if ($newPromise->isCancelled()) {
                    return;
                }

                if ($onFulfilled !== null) {
                    try {
                        $result = $onFulfilled($value);
                        if ($result instanceof PromiseInterface) {
                            $result->then($resolve, $reject);
                        } else {
                            $resolve($result);
                        }
                    } catch (\Throwable $e) {
                        $reject($e);
                    }
                } else {
                    $resolve($value);
                }
            };

            $handleReject = function ($reason) use ($onRejected, $resolve, $reject, &$newPromise) {
                if ($this->isCancelled()) {

                    return;
                }

                assert($newPromise instanceof PromiseInterface);
                if ($newPromise->isCancelled()) {
                    return;
                }

                if ($onRejected !== null) {
                    try {
                        $result = $onRejected($reason);
                        if ($result instanceof PromiseInterface) {
                            $result->then($resolve, $reject);
                        } else {
                            $resolve($result);
                        }
                    } catch (\Throwable $e) {
                        $reject($e);
                    }
                } else {
                    $reject($reason);
                }
            };

            if ($this->isCancelled()) {
                Loop::microTask(fn() => $reject(new \Exception('Promise cancelled')));

                return;
            }

            if ($this->resolved) {
                Loop::microTask(fn() => $handleResolve($this->value));
            } elseif ($this->rejected) {
                Loop::microTask(fn() => $handleReject($this->reason));
            } else {
                $this->thenCallbacks[] = $handleResolve;
                $this->catchCallbacks[] = $handleReject;
            }
        };

        /** @var Promise<TResult> $newPromise */
        $newPromise = new self($executor);

        // Set parent-child relationship for backward cancellation propagation 
        $newPromise->parentPromise = $this;

        $this->childPromises[] = $newPromise;

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
     * @return PromiseInterface<TValue>
     */
    public function finally(callable $onFinally): PromiseInterface
    {
        return $this->then(
            function ($value) use ($onFinally) {
                $result = $onFinally();
                if ($result instanceof PromiseInterface) {
                    return $result->then(fn() => $value);
                }

                return $value;
            },
            function ($reason) use ($onFinally): PromiseInterface {
                $result = $onFinally();
                if ($result instanceof PromiseInterface) {
                    return $result->then(function () use ($reason): void {
                        if ($reason instanceof \Throwable) {
                            throw $reason;
                        }

                        throw new \Exception($this->safeStringCast($reason));
                    });
                }

                if ($reason instanceof \Throwable) {
                    throw $reason;
                }

                throw new \Exception($this->safeStringCast($reason));
            }
        );
    }

    /**
     * {@inheritdoc}
     */
    public function isResolved(): bool
    {
        return $this->resolved;
    }

    /**
     * {@inheritdoc}
     */
    public function isRejected(): bool
    {
        return $this->rejected;
    }

    /**
     * {@inheritdoc}
     */
    public function isPending(): bool
    {
        return ! $this->resolved && ! $this->rejected && ! $this->cancelled;
    }

    /**
     * {@inheritdoc}
     */
    public function getValue(): mixed
    {
        $this->valueAccessed = true;

        if (! $this->resolved) {
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

        if (! $this->rejected) {
            return null;
        }

        return $this->reason;
    }

    /**
     * Create a resolved promise with the given value.
     *
     * @template TResolveValue
     *
     * @param  TResolveValue  $value  The value to resolve the promise with
     * @return PromiseInterface<TResolveValue> A promise resolved with the provided value
     */
    public static function resolved(mixed $value = null): PromiseInterface
    {
        /** @var Promise<TResolveValue> $promise */
        $promise = new self();
        $promise->resolve($value);

        return $promise;
    }

    /**
     * Create a rejected promise with the given reason.
     *
     * @param  mixed  $reason  The reason for rejection (typically an exception)
     * @return PromiseInterface<mixed> A promise rejected with the provided reason
     */
    public static function rejected(mixed $reason): PromiseInterface
    {
        $promise = new self();
        $promise->reject($reason);

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
        return ! $this->resolved && ! $this->rejected && ! $this->cancelled;
    }

    /**
     * Cancel all child promises (forward propagation).
     * Backward propagation is not supported and only allowed in Promise::race() and Promise::any().
     * This is called when a parent promise is cancelled.
     *
     * @return void
     */
    private function cancelChildren(): void
    {
        foreach ($this->childPromises as $child) {
            if (! $child->isCancelled()) {
                $child->cancel();
            }
        }
        $this->childPromises = [];
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

        if ($this->rejected && ! $this->hasRejectionHandler && ! $this->cancelled) {
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
