<?php

declare(strict_types=1);

namespace Hibla\Promise;

use Hibla\Promise\Interfaces\SettledResultInterface;
use JsonSerializable;

/**
 * @template TValue
 * @template TReason
 *
 * @implements SettledResultInterface<TValue, TReason>
 */
final readonly class SettledResult implements SettledResultInterface
{
    private const string STATUS_FULFILLED = 'fulfilled';
    private const string STATUS_REJECTED = 'rejected';
    private const string STATUS_CANCELLED = 'cancelled';

    private function __construct(
        public string $status,
        public mixed $value = null,
        public mixed $reason = null
    ) {
    }

    /**
     * @internal use in combinator logic
     *
     * @template TFulfilledValue
     * @param TFulfilledValue $value
     * @return self<TFulfilledValue, never>
     */
    public static function fulfilled(mixed $value): self
    {
        /** @var self<TFulfilledValue, never> */
        return new self(self::STATUS_FULFILLED, $value);
    }

    /**
     *  @internal use in combinator logic
     *
     * @template TRejectedReason
     * @param TRejectedReason $reason
     * @return self<never, TRejectedReason>
     */
    public static function rejected(mixed $reason): self
    {
        /** @var self<never, TRejectedReason> */
        return new self(self::STATUS_REJECTED, null, $reason);
    }

    /**
     * @internal use in combinator logic
     *
     * @return self<never, never>
     */
    public static function cancelled(): self
    {
        /** @var self<never, never> */
        return new self(self::STATUS_CANCELLED);
    }

    /**
     * @inheritDoc
     */
    public function isFulfilled(): bool
    {
        return $this->status === self::STATUS_FULFILLED;
    }

    /**
     * @inheritDoc
     */
    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * @inheritDoc
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * @return array{status: 'fulfilled'|'rejected'|'cancelled', value?: mixed, reason?: mixed}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @return array{status: 'fulfilled'|'rejected'|'cancelled', value?: mixed, reason?: mixed}
     */
    private function toArray(): array
    {
        if ($this->isFulfilled()) {
            return [
                'status' => self::STATUS_FULFILLED,
                'value' => $this->value,
            ];
        }

        if ($this->isRejected()) {
            return [
                'status' => self::STATUS_REJECTED,
                'reason' => $this->serializeReason($this->reason),
            ];
        }

        return [
            'status' => self::STATUS_CANCELLED,
        ];
    }

    private function serializeReason(mixed $reason): mixed
    {
        if ($reason instanceof \Throwable) {
            return [
                'message' => $reason->getMessage(),
                'code' => $reason->getCode(),
                'class' => get_class($reason),
                'file' => $reason->getFile(),
                'line' => $reason->getLine(),
                'trace' => $reason->getTraceAsString(),
            ];
        }

        if ($reason instanceof JsonSerializable) {
            return $reason->jsonSerialize();
        }

        if (\is_scalar($reason) || is_null($reason)) {
            return $reason;
        }

        if (\is_array($reason)) {
            return $reason;
        }

        if (\is_object($reason)) {
            if (method_exists($reason, '__toString')) {
                return (string) $reason;
            }

            return \get_class($reason);
        }

        return $reason;
    }
}
