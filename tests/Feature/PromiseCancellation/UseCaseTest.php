<?php

declare(strict_types=1);

use Hibla\Promise\Promise;

describe('Promise Real-World Examples', function () {
    it('file upload with progress tracking', function () {
        $uploadProgress = 0;
        $uploadCancelled = false;
        $tempFileDeleted = false;

        $uploadPromise = new Promise(function ($resolve, $reject) use (&$uploadProgress) {
            $uploadProgress = 25;
        });

        $uploadPromise->onCancel(function () use (&$uploadCancelled, &$tempFileDeleted) {
            $uploadCancelled = true;
            $tempFileDeleted = true;
        });

        $uploadPromise->cancel();

        expect($uploadCancelled)->toBeTrue()
            ->and($tempFileDeleted)->toBeTrue()
            ->and($uploadPromise->isCancelled())->toBeTrue()
        ;
    });

    it('database transaction with rollback', function () {
        $transactionStarted = false;
        $transactionRolledBack = false;

        $dbPromise = new Promise(function ($resolve, $reject) use (&$transactionStarted) {
            $transactionStarted = true;
        });

        $dbPromise->onCancel(function () use (&$transactionRolledBack) {
            $transactionRolledBack = true;
        });

        $dbPromise->cancel();

        expect($transactionStarted)->toBeTrue()
            ->and($transactionRolledBack)->toBeTrue()
            ->and($dbPromise->isCancelled())->toBeTrue()
        ;
    });

    it('API request with connection cleanup', function () {
        $requestSent = false;
        $connectionClosed = false;
        $cacheCleared = false;

        $apiPromise = new Promise(function ($resolve, $reject) use (&$requestSent) {
            $requestSent = true;
        });

        $apiPromise->onCancel(function () use (&$connectionClosed, &$cacheCleared) {
            $connectionClosed = true;
            $cacheCleared = true;
        });

        $apiPromise->cancel();

        expect($requestSent)->toBeTrue()
            ->and($connectionClosed)->toBeTrue()
            ->and($cacheCleared)->toBeTrue()
        ;
    });
});
