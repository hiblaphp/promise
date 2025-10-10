<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

use Hibla\Async\Async;
use Hibla\EventLoop\EventLoop;
use Hibla\Promise\Handlers\CallbackHandler;
use Hibla\Promise\Handlers\ChainHandler;
use Hibla\Promise\Handlers\ExecutorHandler;
use Hibla\Promise\Handlers\ResolutionHandler;
use Hibla\Promise\Handlers\StateHandler;
use Hibla\Promise\Promise;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

function chainHandler(): ChainHandler
{
    return new ChainHandler();
}

function executorHandler(): ExecutorHandler
{
    return new ExecutorHandler();
}

function stateHandler(): StateHandler
{
    return new StateHandler();
}

function callbackHandler(): CallbackHandler
{
    return new CallbackHandler();
}

function resolutionHandler(StateHandler $stateHandler, CallbackHandler $callbackHandler): ResolutionHandler
{
    return new ResolutionHandler($stateHandler, $callbackHandler);
}

/**
 * Resets all core singletons and clears test state.
 *
 * This function is the single source of truth for test setup. By calling it
 * in each test file's `beforeEach` hook, we ensure perfect test isolation.
 */
function resetTest()
{
    EventLoop::reset();
    Async::reset();
    Promise::reset();
}
