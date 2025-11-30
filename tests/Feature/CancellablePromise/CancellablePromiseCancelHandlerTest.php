<?php

declare(strict_types=1);

use Hibla\Promise\CancellablePromise;

describe('CancellablePromise Cancel Handler', function () {
    it('executes cancel handler when cancelled', function () {
        $promise = new CancellablePromise();
        $handlerExecuted = false;

        $promise->setCancelHandler(function () use (&$handlerExecuted) {
            $handlerExecuted = true;
        });

        $promise->cancel();

        expect($handlerExecuted)->toBeTrue();
    });

    it('handles cancel handler exceptions gracefully', function () {
        $promise = new CancellablePromise();

        $promise->setCancelHandler(function () {
            throw new Exception('Handler error');
        });

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
    });

    it('overwrites previous cancel handler when called multiple times', function () {
        $promise = new CancellablePromise();
        $handler1Called = false;
        $handler2Called = false;
        $handler3Called = false;

        $promise->setCancelHandler(function () use (&$handler1Called) {
            $handler1Called = true;
        });

        $promise->setCancelHandler(function () use (&$handler2Called) {
            $handler2Called = true;
        });

        $promise->setCancelHandler(function () use (&$handler3Called) {
            $handler3Called = true;
        });

        $promise->cancel();

        expect($handler1Called)->toBeFalse()
            ->and($handler2Called)->toBeFalse()
            ->and($handler3Called)->toBeTrue()
        ;
    });

    it('can handle cancellation with null cancel handler initially', function () {
        $promise = new CancellablePromise();

        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
    });

    it('executes cleanup operations when cancelled', function () {
        $promise = new CancellablePromise();
        $connectionClosed = false;
        $tempFileDeleted = false;
        $resourcesFreed = false;

        $promise->setCancelHandler(function () use (&$connectionClosed, &$tempFileDeleted, &$resourcesFreed) {
            $connectionClosed = true;
            $tempFileDeleted = true;
            $resourcesFreed = true;
        });

        $promise->cancel();

        expect($connectionClosed)->toBeTrue()
            ->and($tempFileDeleted)->toBeTrue()
            ->and($resourcesFreed)->toBeTrue()
        ;
    });

    it('handles database connection cleanup', function () {
        $promise = new CancellablePromise();
        $dbConnection = new stdClass();
        $dbConnection->isConnected = true;
        $dbConnection->transactionActive = true;

        $promise->setCancelHandler(function () use ($dbConnection) {
            $dbConnection->transactionActive = false;
            $dbConnection->isConnected = false;
        });

        $promise->cancel();

        expect($dbConnection->transactionActive)->toBeFalse()
            ->and($dbConnection->isConnected)->toBeFalse()
        ;
    });

    it('handles complex resource cleanup chain', function () {
        $promise = new CancellablePromise();
        $cleanupLog = [];

        $promise->setCancelHandler(function () use (&$cleanupLog) {
            $cleanupLog[] = '1. Saving current state';
            $cleanupLog[] = '2. Rolling back database transaction';
            $cleanupLog[] = '3. Closing network connections';
            $cleanupLog[] = '4. Releasing memory buffers';
            $cleanupLog[] = '5. Notifying dependent services';
            $cleanupLog[] = '6. Cleanup completed';
        });

        $promise->cancel();

        expect($cleanupLog)->toBe([
            '1. Saving current state',
            '2. Rolling back database transaction',
            '3. Closing network connections',
            '4. Releasing memory buffers',
            '5. Notifying dependent services',
            '6. Cleanup completed',
        ]);
    });

    it('can access promise context in cancel handler', function () {
        $promise = new CancellablePromise();
        $promiseId = 'task-123';
        $cancelContext = null;

        $contextData = ['id' => $promiseId, 'type' => 'file-upload'];

        $promise->setCancelHandler(function () use ($contextData, &$cancelContext) {
            $cancelContext = [
                'cancelled_task' => $contextData['id'],
                'task_type' => $contextData['type'],
                'cancel_time' => date('Y-m-d H:i:s'),
            ];
        });

        $promise->cancel();

        expect($cancelContext['cancelled_task'])->toBe('task-123')
            ->and($cancelContext['task_type'])->toBe('file-upload')
            ->and($cancelContext['cancel_time'])->toMatch('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/')
        ;
    });

    it('handles cancellation with logging and metrics', function () {
        $promise = new CancellablePromise();
        $metrics = [];
        $logs = [];

        $promise->setCancelHandler(function () use (&$metrics, &$logs) {
            $logs[] = '[WARN] Promise cancelled by user';
            $logs[] = '[INFO] Cleaning up resources';

            $metrics['cancelled_operations'] = ($metrics['cancelled_operations'] ?? 0) + 1;
            $metrics['last_cancel_time'] = time();

            $logs[] = '[INFO] Cleanup completed successfully';
        });

        $promise->cancel();

        expect($logs)->toContain('[WARN] Promise cancelled by user')
            ->and($logs)->toContain('[INFO] Cleaning up resources')
            ->and($logs)->toContain('[INFO] Cleanup completed successfully')
            ->and($metrics['cancelled_operations'])->toBe(1)
            ->and($metrics['last_cancel_time'])->toBeInt()
        ;
    });
});
