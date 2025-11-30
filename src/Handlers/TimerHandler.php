<?php

namespace Hibla\Promise\Handlers;

use Hibla\EventLoop\EventLoop;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;

/**
 * Handles timer-based asynchronous operations.
 */
final readonly class TimerHandler
{
    /**
     * Create a Promise that resolves after the specified delay.
     *
     * @param  float  $seconds  Number of seconds to delay (supports fractional seconds)
     * @return CancellablePromiseInterface<null> Promise that resolves after the delay and can be cancelled
     */
    public function delay(float $seconds): CancellablePromiseInterface
    {
        /** @var CancellablePromise<null> $promise */
        $promise = new CancellablePromise();

        $timerId = EventLoop::getInstance()->addTimer($seconds, function () use ($promise): void {
            if (! $promise->isCancelled()) {
                $promise->resolve(null);
            }
        });

        $promise->setTimerId($timerId);

        return $promise;
    }
}
