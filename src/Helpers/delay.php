<?php

declare(strict_types=1);

namespace Hibla;

use Hibla\Promise\Handlers\TimerHandler;
use Hibla\Promise\Interfaces\PromiseInterface;

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
