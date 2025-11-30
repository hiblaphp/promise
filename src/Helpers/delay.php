<?php

declare(strict_types=1);

namespace Hibla;

use Hibla\Promise\Handlers\TimerHandler;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;

/**
 * Create a Promise that resolves after the specified delay.
 *
 * @param  float  $seconds  Number of seconds to delay (supports fractional seconds)
 * @return CancellablePromiseInterface<null> Promise that resolves after the delay and can be cancelled
 */
function delay(float $seconds): CancellablePromiseInterface
{
    /** @var TimerHandler|null $timerHandler */
    static $timerHandler = null;

    $timerHandler ??= new TimerHandler();

    return $timerHandler->delay($seconds);
}
