<?php

declare(strict_types=1);

namespace Hibla\Promise\Handlers;

use Hibla\EventLoop\Loop;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

final readonly class TimerHandler
{
    /**
     * @param  float  $seconds  Number of seconds to delay (supports fractional seconds)
     * @return PromiseInterface<null> Promise that resolves after the delay and can be cancelled
     */
    public function delay(float $seconds): PromiseInterface
    {
        /** @var Promise<null> $promise */
        $promise = new Promise();

        $timerId = Loop::addTimer($seconds, function () use ($promise): void {
            $promise->resolve(null);
        });

        $promise->setCancelHandler(function () use ($timerId): void {
            Loop::cancelTimer($timerId);
        });

        return $promise;
    }
}
