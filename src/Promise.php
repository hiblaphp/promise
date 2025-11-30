<?php

declare(strict_types=1);

namespace Hibla\Promise;

use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\PromiseRejectionException;
use Hibla\Promise\Handlers\ConcurrencyHandler;
use Hibla\Promise\Handlers\PromiseCollectionHandler;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Interfaces\PromiseStaticInterface;

/**
 * A Promise/A+ compliant implementation for managing asynchronous operations.
 *
 * This class provides a robust mechanism for handling eventual results or
 * failures from asynchronous tasks. It supports chaining, error handling,
 * and a clear lifecycle (pending, fulfilled, rejected).
 *
 * @template TValue
 *
 * @implements PromiseInterface<TValue>
 */
class Promise implements PromiseStaticInterface, PromiseInterface
{
    /**
     * @var bool
     */
    private bool $resolved = false;

    /**
     * @var bool
     */
    private bool $rejected = false;

    /**
     * @var mixed
     */
    private mixed $value = null;

    /**
     * @var mixed
     */
    private mixed $reason = null;

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
    private array $finallyCallbacks = [];

    /**
     * @var CancellablePromiseInterface<mixed>|null
     */
    protected ?CancellablePromiseInterface $rootCancellable = null;

    /**
     * @var PromiseCollectionHandler|null
     */
    private static ?PromiseCollectionHandler $collectionHandler = null;

    /**
     * @var ConcurrencyHandler|null
     */
    private static ?ConcurrencyHandler $concurrencyHandler = null;

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
    public function await(bool $resetEventLoop = false): mixed
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
            $completed = false;

            $this
                ->then(function ($value) use (&$result, &$completed) {
                    $result = $value;
                    $completed = true;

                    return $value;
                })
                ->catch(function ($reason) use (&$error, &$completed) {
                    $error = $reason;
                    $completed = true;

                    return $reason;
                })
            ;

            while (! $completed) {
                Loop::run();
            }

            if ($error !== null) {
                throw $error instanceof \Throwable
                    ? $error
                    : new \Exception($this->safeStringCast($error));
            }

            return $result;
        } finally {
            if ($resetEventLoop) {
                EventLoop::reset();
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
     * Check if the Promise can be settled (resolved or rejected).
     *
     * @return bool True if the Promise can be settled, false if already settled
     */
    private function canSettle(): bool
    {
        return ! $this->resolved && ! $this->rejected;
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

        Loop::nextTick(function () use ($value) {
            $callbacks = $this->thenCallbacks;
            $this->thenCallbacks = [];

            foreach ($callbacks as $callback) {
                $callback($value);
            }

            $finallyCallbacks = $this->finallyCallbacks;
            $this->finallyCallbacks = [];

            foreach ($finallyCallbacks as $callback) {
                $callback();
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

        Loop::nextTick(function () {
            $callbacks = $this->catchCallbacks;
            $this->catchCallbacks = [];

            foreach ($callbacks as $callback) {
                $callback($this->reason);
            }

            $finallyCallbacks = $this->finallyCallbacks;
            $this->finallyCallbacks = [];

            foreach ($finallyCallbacks as $callback) {
                $callback();
            }
        });
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
        if ($onRejected !== null) {
            $this->hasRejectionHandler = true;
        }

        // Determine the root cancellable promise
        $root = $this instanceof CancellablePromiseInterface
            ? $this
            : $this->rootCancellable;

        $executor = function (callable $resolve, callable $reject) use ($onFulfilled, $onRejected, $root) {
            $handleResolve = function ($value) use ($onFulfilled, $resolve, $reject, $root) {
                if ($root !== null && $root->isCancelled()) {
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

            $handleReject = function ($reason) use ($onRejected, $resolve, $reject, $root) {
                if ($root !== null && $root->isCancelled()) {
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

            if ($this->resolved) {
                Loop::nextTick(fn () => $handleResolve($this->value));
            } elseif ($this->rejected) {
                Loop::nextTick(fn () => $handleReject($this->reason));
            } else {
                $this->thenCallbacks[] = $handleResolve;
                $this->catchCallbacks[] = $handleReject;
            }
        };

        /** @var Promise<TResult> $newPromise */
        $newPromise = $root !== null
            ? new CancellablePromise($executor)
            : new self($executor);

        // Set root cancellable reference
        if ($this instanceof CancellablePromiseInterface) {
            $newPromise->rootCancellable = $this;
        } elseif ($this->rootCancellable !== null) {
            $newPromise->rootCancellable = $this->rootCancellable;
        }

        // If new promise is cancellable, forward cancellation to root
        if ($newPromise instanceof CancellablePromise && $root !== null) {
            $newPromise->setCancelHandler(function () use ($root) {
                $root->cancel();
            });
        }

        if ($onRejected !== null || $onFulfilled !== null) {
            $this->hasRejectionHandler = true;
        }

        return $newPromise;
    }

    /**
     * @inheritDoc
     */
    public function getRootCancellable(): ?CancellablePromiseInterface
    {
        return $this->rootCancellable;
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
        $this->finallyCallbacks[] = $onFinally;
        $this->hasRejectionHandler = true;

        return $this;
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
        return ! $this->resolved && ! $this->rejected;
    }

    /**
     * {@inheritdoc}
     */
    public function getValue(): mixed
    {
        $this->valueAccessed = true;

        return $this->value;
    }

    /**
     * {@inheritdoc}
     */
    public function getReason(): mixed
    {
        $this->valueAccessed = true;

        return $this->reason;
    }

    /**
     * {@inheritdoc}
     */
    public static function reset(): void
    {
        self::$collectionHandler = null;
        self::$concurrencyHandler = null;
    }

    /**
     * Create a resolved promise with the given value.
     *
     * @template TResolveValue
     *
     * @param  TResolveValue  $value  The value to resolve the promise with
     * @return PromiseInterface<TResolveValue> A promise resolved with the provided value
     */
    public static function resolved(mixed $value): PromiseInterface
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
     * @return CancellablePromiseInterface<TRaceValue> A promise that settles with the first settled promise.
     */
    public static function race(array $promises): CancellablePromiseInterface
    {
        return self::getCollectionHandler()->race($promises);
    }

    /**
     * @inheritDoc
     * @template TAnyValue
     * @param  array<int|string, PromiseInterface<TAnyValue>>  $promises  Array of promises to wait for
     * @return CancellablePromiseInterface<TAnyValue> A promise that resolves with the first settled value
     */
    public static function any(array $promises): CancellablePromiseInterface
    {
        return self::getCollectionHandler()->any($promises);
    }

    /**
     * @inheritDoc
     * @template TTimeoutValue
     * @param  PromiseInterface<TTimeoutValue>  $promise  The promise to add timeout to
     * @param  float  $seconds  Timeout duration in seconds
     * @return CancellablePromiseInterface<TTimeoutValue>
     */
    public static function timeout(PromiseInterface $promise, float $seconds): CancellablePromiseInterface
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
        // Don't report if value/reason was directly accessed (e.g., in tests or directly awaiting the promise)
        if ($this->valueAccessed) {
            return;
        }

        if ($this->rejected && ! $this->hasRejectionHandler) {
            if ($this instanceof CancellablePromiseInterface && $this->isCancelled()) {
                return;
            }

            $reason = $this->reason;
            $message = $reason instanceof \Throwable
                ? \sprintf(
                    "Unhandled promise rejection with %s: %s in %s:%d\nStack trace:\n%s",
                    get_class($reason),
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
