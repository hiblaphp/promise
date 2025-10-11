<?php

describe('ExecutorHandler', function () {
    it('should execute executor with resolve and reject functions', function () {
        $handler = executorHandler();
        $resolvedValue = null;
        $rejectedReason = null;

        $resolve = function ($value) use (&$resolvedValue) {
            $resolvedValue = $value;
        };

        $reject = function ($reason) use (&$rejectedReason) {
            $rejectedReason = $reason;
        };

        $executor = function ($res, $rej) {
            $res('test value');
        };

        $handler->executeExecutor($executor, $resolve, $reject);

        expect($resolvedValue)->toBe('test value')
            ->and($rejectedReason)->toBeNull()
        ;
    });

    it('should handle executor that rejects', function () {
        $handler = executorHandler();
        $resolvedValue = null;
        $rejectedReason = null;

        $resolve = function ($value) use (&$resolvedValue) {
            $resolvedValue = $value;
        };

        $reject = function ($reason) use (&$rejectedReason) {
            $rejectedReason = $reason;
        };

        $executor = function ($res, $rej) {
            $rej('error reason');
        };

        $handler->executeExecutor($executor, $resolve, $reject);

        expect($rejectedReason)->toBe('error reason')
            ->and($resolvedValue)->toBeNull()
        ;
    });

    it('should handle executor exceptions by rejecting', function () {
        $handler = executorHandler();
        $resolvedValue = null;
        $rejectedReason = null;

        $resolve = function ($value) use (&$resolvedValue) {
            $resolvedValue = $value;
        };

        $reject = function ($reason) use (&$rejectedReason) {
            $rejectedReason = $reason;
        };

        $executor = function () {
            throw new Exception('executor error');
        };

        $handler->executeExecutor($executor, $resolve, $reject);

        expect($rejectedReason)->toBeInstanceOf(Exception::class)
            ->and($rejectedReason->getMessage())->toBe('executor error')
            ->and($resolvedValue)->toBeNull()
        ;
    });

    it('should handle null executor gracefully', function () {
        $handler = executorHandler();
        $resolvedValue = null;
        $rejectedReason = null;

        $resolve = function ($value) use (&$resolvedValue) {
            $resolvedValue = $value;
        };

        $reject = function ($reason) use (&$rejectedReason) {
            $rejectedReason = $reason;
        };

        $handler->executeExecutor(null, $resolve, $reject);

        expect($resolvedValue)->toBeNull()
            ->and($rejectedReason)->toBeNull()
        ;
    });

    it('should handle executor with complex operations', function () {
        $handler = executorHandler();
        $resolvedValue = null;

        $resolve = function ($value) use (&$resolvedValue) {
            $resolvedValue = $value;
        };

        $reject = function () {};

        $executor = function ($res, $rej) {
            $result = array_map(fn ($x) => $x * 2, [1, 2, 3]);
            $res($result);
        };

        $handler->executeExecutor($executor, $resolve, $reject);

        expect($resolvedValue)->toBe([2, 4, 6]);
    });
});
