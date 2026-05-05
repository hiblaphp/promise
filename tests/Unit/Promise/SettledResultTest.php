<?php

declare(strict_types=1);

use Hibla\Promise\PromiseState;
use Hibla\Promise\SettledResult;

describe('SettledResult status values match PromiseState enum', function () {
    it('fulfilled status matches PromiseState::FULFILLED', function () {
        $result = SettledResult::fulfilled('value');

        expect($result->status)->toBe(PromiseState::FULFILLED->value)
            ->and($result->isFulfilled())->toBeTrue()
        ;
    });

    it('rejected status matches PromiseState::REJECTED', function () {
        $result = SettledResult::rejected(new RuntimeException('error'));

        expect($result->status)->toBe(PromiseState::REJECTED->value)
            ->and($result->isRejected())->toBeTrue()
        ;
    });

    it('cancelled status matches PromiseState::CANCELLED', function () {
        $result = SettledResult::cancelled();

        expect($result->status)->toBe(PromiseState::CANCELLED->value)
            ->and($result->isCancelled())->toBeTrue()
        ;
    });

    it('serializes fulfilled result correctly', function () {
        $result = SettledResult::fulfilled(42);
        $serialized = $result->jsonSerialize();

        expect($serialized)->toBe([
            'status' => PromiseState::FULFILLED->value,
            'value' => 42,
        ]);
    });

    it('serializes rejected result with Throwable reason', function () {
        $exception = new RuntimeException('fail', 500);
        $result = SettledResult::rejected($exception);
        $serialized = $result->jsonSerialize();

        expect($serialized['status'])->toBe(PromiseState::REJECTED->value)
            ->and($serialized['reason'])->toBeArray()
            ->and($serialized['reason']['message'])->toBe('fail')
            ->and($serialized['reason']['code'])->toBe(500)
        ;
    });

    it('serializes cancelled result correctly', function () {
        $result = SettledResult::cancelled();
        $serialized = $result->jsonSerialize();

        expect($serialized)->toBe(['status' => PromiseState::CANCELLED->value]);
    });
});
