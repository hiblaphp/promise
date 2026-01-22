<?php

declare(strict_types=1);

namespace Hibla\Promise\Exceptions;

/**
 * Thrown when attempting to wait() on a cancelled promise.
 *
 * This indicates a programming error: cancelling an operation states you
 * don't want its result, so attempting to wait for that result represents
 * a logic error in your code flow.
 *
 * Note: Cancellation itself never throws - only waiting on cancelled promises.
 */
class CancelledException extends \LogicException
{
}
