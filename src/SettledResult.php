<?php

declare(strict_types=1);

namespace Hibla\Promise;

/**
 * @template TValue
 * @template TReason
 */
final class SettledResult
{
    /**
     * @param TValue $value
     * @param TReason $reason
     */
    private function __construct(
        public readonly string $status,
        public readonly mixed $value = null,
        public readonly mixed $reason = null,
    ) {
    }

    /**
     * @template TFulfilledValue
     * @param TFulfilledValue $value
     * @return self<TFulfilledValue, never>
     */
    public static function fulfilled(mixed $value): self
    {
        /** @var self<TFulfilledValue, never> */
        return new self('fulfilled', $value);
    }

    /**
     * @template TRejectedReason
     * @param TRejectedReason $reason
     * @return self<never, TRejectedReason>
     */
    public static function rejected(mixed $reason): self
    {
        /** @var self<never, TRejectedReason> */
        return new self('rejected', null, $reason);
    }

    /**
     * @return self<never, never>
     */
    public static function cancelled(): self
    {
        /** @var self<never, never> */
        return new self('cancelled');
    }

    public function isFulfilled(): bool
    {
        return $this->status === 'fulfilled';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }
}
