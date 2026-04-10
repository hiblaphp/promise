<?php

declare(strict_types=1);

namespace Hibla\Promise\Interfaces;

use JsonSerializable;

/**
 * @template-covariant TValue
 * @template-covariant TReason
 */
interface SettledResultInterface extends JsonSerializable
{
    /**
     * Returns the promise status of a settled result.
     */
    public string $status { get; }

    /**
     * Returns the value of a settled result.
     *
     * @return TValue|null
     */
    public mixed $value { get; }

    /**
     * Returns the reason  of a settled result.
     *
     * @return TReason|null
     */
    public mixed $reason { get; }

    /**
     * Returns true if the settled result is fulfilled.
     */
    public function isFulfilled(): bool;

    /**
     * Returns true if the settled result is rejected.
     */
    public function isRejected(): bool;

    /**
     * Returns true if the settled result is cancelled.
     */
    public function isCancelled(): bool;
}
