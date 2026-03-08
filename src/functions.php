<?php

declare(strict_types=1);

namespace Hibla;

use Hibla\Promise\Handlers\TimerHandler;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

/**
 * Create a Promise that resolves after the specified delay.
 *
 * @param  float  $seconds  Number of seconds to delay (supports fractional seconds)
 * @return PromiseInterface<null> Promise that resolves after the delay and can be cancelled
 */
function delay(float $seconds): PromiseInterface
{
    /** @var TimerHandler|null $timerHandler */
    static $timerHandler = null;

    $timerHandler ??= new TimerHandler();

    return $timerHandler->delay($seconds);
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
 * WARNING: The handler MUST NOT throw. PHP silently swallows exceptions
 * thrown from __destruct(). Wrap your handler body in try/catch if needed.
 *
 * @param  callable(mixed $reason, PromiseInterface<mixed> $promise): void|null  $handler
 * @return callable(mixed $reason, PromiseInterface<mixed> $promise): void|null
 */
function setRejectionHandler(callable $handler): ?callable
{
    return Promise::setRejectionHandler($handler);
}
