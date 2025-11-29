<?php

namespace Hibla\Promise;

use Hibla\Async\AsyncOperations;
use Hibla\EventLoop\EventLoop;
use Hibla\Promise\Exceptions\PromiseRejectionException;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseCollectionInterface;
use Hibla\Promise\Interfaces\PromiseInterface;

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
class Promise implements PromiseCollectionInterface, PromiseInterface
{
    /**
     * @var bool Whether the Promise has been resolved
     */
    private bool $resolved = false;

    /**
     * @var bool Whether the Promise has been rejected
     */
    private bool $rejected = false;

    /**
     * @var mixed The resolved value (if resolved)
     */
    private mixed $value = null;

    /**
     * @var mixed The rejection reason (if rejected)
     */
    private mixed $reason = null;

    /**
     * @var array<callable> Callbacks to execute when Promise resolves
     */
    private array $thenCallbacks = [];

    /**
     * @var array<callable> Callbacks to execute when Promise rejects
     */
    private array $catchCallbacks = [];

    /**
     * @var array<callable> Callbacks to execute when Promise settles (resolve or reject)
     */
    private array $finallyCallbacks = [];

    /**
     * @var CancellablePromiseInterface<mixed>|null
     */
    protected ?CancellablePromiseInterface $rootCancellable = null;

    /**
     * @var AsyncOperations|null Static instance for collection operations
     */
    private static ?AsyncOperations $asyncOps = null;

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
                EventLoop::getInstance()->run();
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

        EventLoop::getInstance()->nextTick(function () use ($value) {
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

        EventLoop::getInstance()->nextTick(function () {
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
                EventLoop::getInstance()->nextTick(fn() => $handleResolve($this->value));
            } elseif ($this->rejected) {
                EventLoop::getInstance()->nextTick(fn() => $handleReject($this->reason));
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
     * Get or create the AsyncOperations instance for static methods.
     */
    private static function getAsyncOps(): AsyncOperations
    {
        return self::$asyncOps ??= new AsyncOperations();
    }

    /**
     * {@inheritdoc}
     */
    public static function reset(): void
    {
        self::$asyncOps = null;
    }

    /**
     * {@inheritdoc}
     *
     * @template TResolvedValue
     * @param TResolvedValue $value
     * @return PromiseInterface<TResolvedValue>
     */
    public static function resolved(mixed $value): PromiseInterface
    {
        /** @var Promise<TResolvedValue> $promise */
        $promise = new self();
        $promise->resolve($value);

        return $promise;
    }

    /**
     * {@inheritdoc}
     */
    public static function rejected(mixed $reason): PromiseInterface
    {
        $promise = new self();
        $promise->reject($reason);

        return $promise;
    }

    /**
     * {@inheritdoc}
     *
     * @template TAllValue
     * @param  array<int|string, PromiseInterface<TAllValue>|callable(): PromiseInterface<TAllValue>>  $promises
     * @return PromiseInterface<array<int|string, TAllValue>>
     */
    public static function all(array $promises): PromiseInterface
    {
        return self::getAsyncOps()->all($promises);
    }

    /**
     * {@inheritdoc}
     *
     * @template TAllSettledValue
     * @param  array<int|string, PromiseInterface<TAllSettledValue>|callable(): PromiseInterface<TAllSettledValue>>  $promises
     * @return PromiseInterface<array<int|string, array{status: 'fulfilled'|'rejected', value?: TAllSettledValue, reason?: mixed}>>
     */
    public static function allSettled(array $promises): PromiseInterface
    {
        return self::getAsyncOps()->allSettled($promises);
    }

    /**
     * {@inheritdoc}
     *
     * @template TRaceValue
     * @param  array<int|string, PromiseInterface<TRaceValue>|callable(): PromiseInterface<TRaceValue>>  $promises
     * @return PromiseInterface<TRaceValue>
     */
    public static function race(array $promises): PromiseInterface
    {
        return self::getAsyncOps()->race($promises);
    }

    /**
     * {@inheritdoc}
     *
     * @template TAnyValue
     * @param  array<int|string, PromiseInterface<TAnyValue>|callable(): PromiseInterface<TAnyValue>>  $promises
     * @return PromiseInterface<TAnyValue>
     */
    public static function any(array $promises): PromiseInterface
    {
        return self::getAsyncOps()->any($promises);
    }

    /**
     * {@inheritdoc}
     *
     * @template TTimeoutValue
     * @param  PromiseInterface<TTimeoutValue>  $promise
     * @param  float  $seconds
     * @return PromiseInterface<TTimeoutValue>
     */
    public static function timeout(PromiseInterface $promise, float $seconds): PromiseInterface
    {
        return self::getAsyncOps()->timeout($promise, $seconds);
    }

    /**
     * {@inheritdoc}
     *
     * @template TConcurrentValue
     * @param  array<int|string, callable(): (TConcurrentValue|PromiseInterface<TConcurrentValue>)>  $tasks
     * @param  int  $concurrency
     * @return PromiseInterface<array<int|string, TConcurrentValue>>
     */
    public static function concurrent(array $tasks, int $concurrency = 10): PromiseInterface
    {
        return self::getAsyncOps()->concurrent($tasks, $concurrency);
    }

    /**
     * {@inheritdoc}
     *
     * @template TBatchValue
     * @param  array<int|string, callable(): (TBatchValue|PromiseInterface<TBatchValue>)>  $tasks
     * @param  int  $batchSize
     * @param  int|null  $concurrency
     * @return PromiseInterface<array<int|string, TBatchValue>>
     */
    public static function batch(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface
    {
        return self::getAsyncOps()->batch($tasks, $batchSize, $concurrency);
    }

    /**
     * {@inheritdoc}
     *
     * @template TConcurrentSettledValue
     * @param  array<int|string, callable(): (TConcurrentSettledValue|PromiseInterface<TConcurrentSettledValue>)>  $tasks
     * @param  int  $concurrency
     * @return PromiseInterface<array<int|string, array{status: 'fulfilled'|'rejected', value?: TConcurrentSettledValue, reason?: mixed}>>
     */
    public static function concurrentSettled(array $tasks, int $concurrency = 10): PromiseInterface
    {
        return self::getAsyncOps()->concurrentSettled($tasks, $concurrency);
    }

    /**
     * {@inheritdoc}
     *
     * @template TBatchSettledValue
     * @param  array<int|string, callable(): (TBatchSettledValue|PromiseInterface<TBatchSettledValue>)>  $tasks
     * @param  int  $batchSize
     * @param  int|null  $concurrency
     * @return PromiseInterface<array<int|string, array{status: 'fulfilled'|'rejected', value?: TBatchSettledValue, reason?: mixed}>>
     */
    public static function batchSettled(array $tasks, int $batchSize = 10, ?int $concurrency = null): PromiseInterface
    {
        return self::getAsyncOps()->batchSettled($tasks, $batchSize, $concurrency);
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
