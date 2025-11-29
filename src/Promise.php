<?php

namespace Hibla\Promise;

use Hibla\Async\AsyncOperations;
use Hibla\Promise\Handlers\AwaitHandler;
use Hibla\Promise\Handlers\CallbackHandler;
use Hibla\Promise\Handlers\ChainHandler;
use Hibla\Promise\Handlers\ExecutorHandler;
use Hibla\Promise\Handlers\ResolutionHandler;
use Hibla\Promise\Handlers\StateHandler;
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
     * @var StateHandler Manages the promise's state (pending, resolved, rejected)
     */
    private StateHandler $stateHandler;

    /**
     * @var CallbackHandler Manages then, catch, and finally callback queues
     */
    private CallbackHandler $callbackHandler;

    /**
     * @var ExecutorHandler Handles the initial executor function execution
     */
    private ExecutorHandler $executorHandler;

    /**
     * @var ChainHandler Manages promise chaining and callback scheduling
     */
    private ChainHandler $chainHandler;

    /**
     * @var ResolutionHandler Handles promise resolution and rejection logic
     */
    private ResolutionHandler $resolutionHandler;

    /**
     * @var CancellablePromiseInterface<mixed>|null
     */
    protected ?CancellablePromiseInterface $rootCancellable = null;

    private AwaitHandler $awaitHandler;

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
     * @param  callable|null  $executor  Function to execute immediately with resolve/reject callbacks
     */
    public function __construct(?callable $executor = null)
    {
        $this->stateHandler = new StateHandler();
        $this->callbackHandler = new CallbackHandler();
        $this->executorHandler = new ExecutorHandler();
        $this->chainHandler = new ChainHandler();
        $this->resolutionHandler = new ResolutionHandler(
            $this->stateHandler,
            $this->callbackHandler
        );

        $this->executorHandler->executeExecutor(
            $executor,
            fn($value = null) => $this->resolve($value),
            fn($reason = null) => $this->reject($reason)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function await(bool $resetEventLoop = false): mixed
    {
        $this->awaitHandler ??= new AwaitHandler();

        return $this->awaitHandler->await($this, $resetEventLoop);
    }

    /**
     * {@inheritdoc}
     */
    public function isSettled(): bool
    {
        // A promise is settled if it is no longer pending.
        return ! $this->stateHandler->isPending();
    }

    /**
     * Resolve the promise with a value.
     *
     * If the promise is already settled, this operation has no effect.
     * The resolution triggers all registered fulfillment callbacks.
     *
     * @param  mixed  $value  The value to resolve the promise with
     */
    public function resolve(mixed $value): void
    {
        $this->resolutionHandler->handleResolve($value);
    }

    /**
     * Reject the promise with a reason.
     *
     * If the promise is already settled, this operation has no effect.
     * The rejection triggers all registered rejection callbacks.
     *
     * @param  mixed  $reason  The reason for rejection (typically an exception)
     */
    public function reject(mixed $reason): void
    {
        $this->resolutionHandler->handleReject($reason);
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

        $stateHandler = $this->stateHandler;
        $callbackHandler = $this->callbackHandler;
        $chainHandler = $this->chainHandler;

        $executor = function (callable $resolve, callable $reject) use ($onFulfilled, $onRejected, $root, $stateHandler, $callbackHandler, $chainHandler) {
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

            if ($stateHandler->isResolved()) {
                $chainHandler->scheduleHandler(fn() => $handleResolve($stateHandler->getValue()));
            } elseif ($stateHandler->isRejected()) {
                $chainHandler->scheduleHandler(fn() => $handleReject($stateHandler->getReason()));
            } else {
                $callbackHandler->addThenCallback($handleResolve);
                $callbackHandler->addCatchCallback($handleReject);
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
        $this->callbackHandler->addFinallyCallback($onFinally);
        $this->hasRejectionHandler = true;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function isResolved(): bool
    {
        return $this->stateHandler->isResolved();
    }

    /**
     * {@inheritdoc}
     */
    public function isRejected(): bool
    {
        return $this->stateHandler->isRejected();
    }

    /**
     * {@inheritdoc}
     */
    public function isPending(): bool
    {
        return $this->stateHandler->isPending();
    }

    /**
     * {@inheritdoc}
     */
    public function getValue(): mixed
    {
        $this->valueAccessed = true;

        return $this->stateHandler->getValue();
    }

    /**
     * {@inheritdoc}
     */
    public function getReason(): mixed
    {
        $this->valueAccessed = true;

        return $this->stateHandler->getReason();
    }

    /**
     * Get or create the AsyncOperations instance for static methods.
     */
    private static function getAsyncOps(): AsyncOperations
    {
        if (self::$asyncOps === null) {
            self::$asyncOps = new AsyncOperations();
        }

        return self::$asyncOps;
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
     */
    public static function resolved(mixed $value): PromiseInterface
    {
        return self::getAsyncOps()->resolved($value);
    }

    /**
     * {@inheritdoc}
     */
    public static function rejected(mixed $reason): PromiseInterface
    {
        return self::getAsyncOps()->rejected($reason);
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

    public function __destruct()
    {
        // Don't report if value/reason was directly accessed (e.g., in tests)
        if ($this->valueAccessed) {
            return;
        }

        if ($this->stateHandler->isRejected() && ! $this->hasRejectionHandler) {
            if ($this instanceof CancellablePromiseInterface && $this->isCancelled()) {
                return;
            }

            $reason = $this->stateHandler->getReason();
            $message = $reason instanceof \Throwable
                ? sprintf(
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
