<?php

declare(strict_types=1);

namespace Hibla\Promise;

use Hibla\EventLoop\EventLoop;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * A promise that can be cancelled to clean up resources.
 *
 * @template TValue
 *
 * @extends Promise<TValue>
 *
 * @implements CancellablePromiseInterface<TValue>
 */
class CancellablePromise extends Promise implements CancellablePromiseInterface
{
    private ?string $timerId = null;
    private bool $cancelled = false;

    /**
     * @var callable|null
     */
    private $cancelHandler = null;

    /**
     * {@inheritdoc}
     */
    public function setTimerId(string $timerId): void
    {
        $this->timerId = $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function cancel(): void
    {
        if (! $this->cancelled) {
            $this->cancelled = true;

            if ($this->cancelHandler !== null) {
                try {
                    ($this->cancelHandler)();
                } catch (\Throwable $e) {
                    error_log('Cancel handler error: ' . $e->getMessage());
                }
            }

            if ($this->timerId !== null) {
                EventLoop::getInstance()->cancelTimer($this->timerId);
            }

            $this->reject(new \Exception('Promise cancelled'));
        }
    }

    public function setCancelHandler(callable $handler): void
    {
        $this->cancelHandler = $handler;
    }

    /**
     * {@inheritdoc}
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * {@inheritdoc}
     *
     * Returns a CancellablePromise to maintain cancellability through the chain.
     *
     * @template TResult
     *
     * @param  callable(TValue): (TResult|PromiseInterface<TResult>)|null  $onFulfilled
     * @param  callable(mixed): (TResult|PromiseInterface<TResult>)|null  $onRejected
     * @return CancellablePromiseInterface<TResult>
     */
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): CancellablePromiseInterface
    {
        $result = parent::then($onFulfilled, $onRejected);

        /** @var CancellablePromiseInterface<TResult> $result */
        return $result;
    }

    /**
     * {@inheritdoc}
     *
     * @template TResult
     *
     * @param  callable(mixed): (TResult|PromiseInterface<TResult>)  $onRejected
     * @return CancellablePromiseInterface<TResult>
     */
    public function catch(callable $onRejected): CancellablePromiseInterface
    {
        $result = parent::catch($onRejected);

        /** @var CancellablePromiseInterface<TResult> $result */
        return $result;
    }

    /**
     * {@inheritdoc}
     *
     * @return CancellablePromiseInterface<TValue>
     */
    public function finally(callable $onFinally): CancellablePromiseInterface
    {
        $result = parent::finally($onFinally);

        /** @var CancellablePromiseInterface<TValue> $result */
        return $result;
    }
}
