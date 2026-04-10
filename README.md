# Hibla Promise

**A Promise/A+-inspired async primitive with first-class cancellation for PHP.**

A high-performance, cancellable Promise implementation built on top of the
[Hibla Event Loop](https://github.com/hiblaphp/event-loop). Extends the
standard 3-state Promise model with a distinct cancelled state, cooperative
cancellation, structured concurrency, and a rich set of collection and
concurrency utilities.

[![Latest Release](https://img.shields.io/github/release/hiblaphp/promise.svg?style=flat-square)](https://github.com/hiblaphp/promise/releases)
[![Tests](https://github.com/hiblaphp/promise/actions/workflows/test.yml/badge.svg)](https://github.com/hiblaphp/promise/actions/workflows/test.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/hiblaphp/promise.svg?style=flat-square)](https://packagist.org/packages/hiblaphp/promise)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](./LICENSE)

---

## Contents

**Getting started**
- [Installation](#installation)
- [What is a Promise](#what-is-a-promise)
- [How Hibla Promise Differs](#how-hibla-promise-differs-from-other-implementations)
  - [A fourth state: `cancelled`](#a-fourth-state-cancelled)
  - [Why `cancelled` enables structured concurrency](#the-cancelled-state-is-what-makes-structured-concurrency-possible)

**Promises**
- [Basic Usage](#basic-usage)
  - [Creating a Promise](#creating-a-promise)
  - [Resolving and rejecting manually](#resolving-and-rejecting-manually)
  - [Pre-settled promises](#pre-settled-promises)
- [The Deferred Pattern](#the-deferred-pattern)
  - [Always pair with `onCancel()`](#always-pair-with-oncancel)
- [Chaining](#chaining)
  - [`then()` always runs asynchronously](#then-always-runs-asynchronously-via-microtask)
  - [Long chains do not overflow the stack](#long-chains-do-not-overflow-the-stack)
  - [`catch()` — handling rejection](#catch--handling-rejection)
  - [Thenable interoperability](#thenable-interoperability)
  - [Cancellation and thenable interoperability](#cancellation-and-thenable-interoperability)
  - [Cyclic chain detection](#cyclic-chain-detection)
  - [`finally()` — always runs](#finally--always-runs)
- [Blocking — `wait()`](#blocking--wait)
- [Async Delay](#async-delay)

**Cancellation**
- [The 4-state model](#the-4-state-model)
- [Cancellation is synchronous](#cancellation-is-synchronous)
- [Cooperative cancellation — `onCancel()`](#cooperative-cancellation--oncancel)
- [`onCancel()` handlers must not throw](#oncancel-handlers-must-not-throw)
- [`cancel()` vs `cancelChain()`](#cancel-vs-cancelchain)
- [The cost of non-cooperative cancellation](#the-cost-of-non-cooperative-cancellation)

**Collections**
- [`SettledResult`](#settledresult)
- [Accepting iterables](#accepting-iterables)
- [Structured concurrency](#structured-concurrency)
- [`Promise::all()`](#promiseall)
- [`Promise::allSettled()`](#promiseallsettled)
- [`Promise::race()`](#promiserace)
- [`Promise::any()`](#promiseany)
- [`Promise::timeout()`](#promisetimeout)
- [Combinator behavior summary](#combinator-behavior-summary)

**Concurrency utilities**
- [Accepting iterables](#accepting-iterables-1)
- [Structured concurrency in concurrency utilities](#structured-concurrency-in-concurrency-utilities)
- [`Promise::concurrent()`](#promiseconcurrent)
- [`Promise::concurrentSettled()`](#promiseconcurrentsettled)
- [`Promise::batch()`](#promisebatch)
- [`Promise::batchSettled()`](#promisebatchsettled)
- [`Promise::map()`](#promisemap)
- [`Promise::mapSettled()`](#promisemapsettled)
- [`Promise::filter()`](#promisefilter)
- [`Promise::reduce()`](#promisereduce)
- [`Promise::forEach()`](#promiseforeach)
- [`Promise::forEachSettled()`](#promiseforeachsettled)
- [Concurrency utility summary](#concurrency-utility-summary)

**Reference**
- [Unhandled Rejections](#unhandled-rejections)
- [API Reference](#api-reference)
  - [Instance methods](#instance-methods)
  - [Static methods](#static-methods)
  - [Global functions](#global-functions)
- [PHPStan Integration](#phpstan-integration)

**Meta**
- [Development](#development)
- [Credits](#credits)
- [License](#license)

---

## Installation
>Hibla Promise is currently in its Alpha development phase. You can install the library via Composer by explicitly allowing alpha stability:

```bash
composer require hiblaphp/promise:^1.0@alpha
```

**Requirements:**
- PHP 8.4+
- `hiblaphp/event-loop`

---

## What is a Promise?

Asynchronous code (HTTP requests, database queries, file reads, timers)
doesn't return a value immediately. Traditionally in PHP, you'd pass a
callback to be called when the work finishes. This works for simple cases,
but as logic grows, callbacks nest inside callbacks, error handling scatters
across functions, and the execution order becomes hard to follow. This is
commonly called **callback hell**.
```php
// Callback hell — error handling is duplicated, nesting grows with every step
fetchUser(1, function ($user, $error) {
    if ($error) {
        logError($error);
        return;
    }
    fetchOrders($user->id, function ($orders, $error) {
        if ($error) {
            logError($error);
            return;
        }
        fetchInvoices($orders, function ($invoices, $error) {
            if ($error) {
                logError($error);
                return;
            }
            // now you can finally do something useful
        });
    });
});
```

A **Promise** is an object that represents the eventual result of an async
operation. Instead of passing a callback into a function, the function
returns a Promise: a placeholder you can hold, pass around, chain, and
attach handlers to later. Error handling consolidates into a single `catch()`
at the end of the chain, and the flow reads top to bottom like synchronous
code:
```php
fetchUser(1)
    ->then(fn($user)     => fetchOrders($user->id))
    ->then(fn($orders)   => fetchInvoices($orders))
    ->then(fn($invoices) => processInvoices($invoices))
    ->catch(fn($e)       => logError($e)); // one handler covers the entire chain
```

Every standard promise has three states:

| State       | Meaning                                                                  |
| ----------- | ------------------------------------------------------------------------ |
| `pending`   | The async work is still in progress.                                     |
| `fulfilled` | The work completed successfully and the promise holds a value.           |
| `rejected`  | The work failed and the promise holds a reason (typically an exception). |

State transitions are **one-way and final**. Once a promise leaves `pending`,
it can never change state again: a fulfilled promise stays fulfilled, a
rejected promise stays rejected. Handlers attached via `then()` and `catch()`
are guaranteed to fire at most once.

Hibla Promise is inspired by the
[Promise/A+ specification](https://promisesaplus.com/) and the JavaScript
`Promise` API, and is fully interoperable with other PHP promise libraries
like [ReactPHP](https://github.com/reactphp/promise) and
[Guzzle](https://github.com/guzzle/promises) that return thenables. You can
return a ReactPHP or Guzzle promise inside a Hibla `then()` handler and the
chain will correctly wait for it. However, Hibla makes several deliberate,
opinionated departures that those implementations do not.

---

## How Hibla Promise Differs from Other Implementations

### A fourth state: `cancelled`

Every standard promise implementation (JavaScript native promises, ReactPHP,
Guzzle) defines exactly three states. Hibla adds a fourth: **`cancelled`**.

In all other implementations, the only way to abandon a promise is to reject
it with a special cancellation exception. This forces you to distinguish
between "the HTTP request timed out" and "the user clicked cancel" by
inspecting the exception type at every catch site. Cancellation is a normal
control-flow decision and not a failure, yet every other implementation
models it as one.

In Hibla, `cancelled` is a proper terminal state, entirely separate from
`rejected`. A cancelled promise never calls `then()` or `catch()` handlers.
It does not pretend to have failed. It simply stopped. This clean separation
means **cleanup logic and error handling live in completely different places**:
`onCancel()` handles resource cleanup when work is deliberately aborted,
while `catch()` handles genuine failures. The two concerns never bleed into
each other.
```
Standard Promise implementations:

  pending --> fulfilled
          --> rejected  <- cancellation disguised as failure,
                          cleanup mixed with error handling


Hibla Promise:

  pending --> fulfilled
          --> rejected   <- genuine failures, handled in catch()
          --> cancelled  <- deliberate abort, handled in onCancel()
```
```php
// Standard promise implementations — cancellation is modelled as rejection.
// Cleanup and error handling are mixed together in the same catch() handler.
// Every catch site has to inspect the exception type to separate the two.
$promise->cancel();
$promise->catch(function (\Throwable $e) {
    if ($e instanceof CancellationException) {
        // cleanup path — but this is inside catch(), which is meant for errors
    } else {
        // error path — but now every error handler carries cancellation boilerplate
    }
});

// Hibla — cleanup and error handling are completely separate concerns
$promise->onCancel(function () {
    // cleanup path — runs only on deliberate cancellation
    // never triggered by a real failure
    closeConnection();
    releaseResources();
});

$promise->catch(function (\Throwable $e) {
    // error path — runs only on genuine failures
    // never triggered by a cancellation
    logError($e);
    notifyUser($e);
});

$promise->cancel();

$promise->isCancelled(); // true  — clean, unambiguous
$promise->isRejected();  // false — nothing went wrong
$promise->isFulfilled(); // false — no result was produced
```

Critically, **cancellation only applies to pending promises**. Once a promise
has fulfilled or rejected, its result is final. Calling `cancel()` on an
already-settled promise is always a no-op.
```php
// Cancelling an already-fulfilled promise — no-op
$promise = Promise::resolved('done');
$promise->cancel();
$promise->isCancelled(); // false
$promise->isFulfilled(); // true — result is unchanged

// Cancelling an already-rejected promise — no-op
$promise = Promise::rejected(new \RuntimeException('Failed'));
$promise->cancel();
$promise->isCancelled(); // false
$promise->isRejected();  // true — rejection reason is unchanged

// Cancelling a pending promise — works as expected
$promise = new Promise();
$promise->cancel();
$promise->isCancelled(); // true

// Race condition — resolve wins if it arrives before cancel()
$promise = new Promise();
$promise->resolve('done');
$promise->cancel();       // no-op — already fulfilled
$promise->isCancelled();  // false
$promise->isFulfilled();  // true
```

This guarantee means you never need to guard against a promise being both
cancelled and fulfilled simultaneously. Whichever transition happens first
wins, and all subsequent state changes are ignored.

### The cancelled state is what makes structured concurrency possible

The cancelled state was a deliberate design decision: cancellation is not a
failure, so it should not live in the failure state. What was not anticipated
was what that decision unlocked. During experimentation with the combinators,
a deeper consequence emerged: because the library could now reliably tell the
difference between a promise that failed and one that was deliberately stopped,
the collection combinators gained the ability to enforce controlled scope
naturally, without any special mechanism designed for that purpose.

Structured concurrency requires that when one operation in a group fails, all
sibling operations can be cleanly stopped and the failure reason propagates
outward accurately. For this to work, the library needs to reliably distinguish
between two fundamentally different situations:

- A promise that **rejected on its own**: something went wrong, the error
  should propagate
- A promise that was **cancelled as a side effect** of a sibling failing:
  nothing went wrong with this promise, it was deliberately stopped, and its
  stopping should not be reported as an error

Without a distinct cancelled state this distinction is impossible. If
cancellation is just rejection with a special exception, a combinator like
`all()` cannot tell whether a sibling rejected because of a genuine failure
or because it was cancelled as cleanup after another sibling failed. The
combinator would have to inspect exception types at every level, which is
fragile, leaks implementation details, and corrupts the error that eventually
reaches the `catch()` handler. Instead of the original failure reason, the
caller gets a cascade of cancellation exceptions from all the siblings that
were stopped.

This is the exact problem Kotlin documents with its `Deferred` type. Because
Kotlin merges cancelled into the rejection path via `CancellationException`,
that exception ends up leading a double life. The same exception type is thrown
for two entirely different reasons (genuine cancellation and operational
failure), and developers have to manually inspect and filter it at every catch
site to tell them apart.

With a distinct cancelled state the logic in every combinator is exact and
unambiguous:

- `isRejected()`: something went wrong, propagate the error outward
- `isCancelled()`: deliberately stopped, clean up silently, do not report
  as an error

This is why `Promise::all()` can cancel siblings when one fails and still
report only the original failure reason to the caller. The cancelled siblings
do not contribute any error of their own. Their `onCancel()` handlers run,
their resources are freed, and they disappear cleanly. The `catch()` handler
at the top receives exactly one error: the one that actually caused the
failure.
```php
Promise::all([
    fetchUser(1),    // rejects with DatabaseException
    fetchOrders(1),  // cancelled as side effect — onCancel() fires, no error reported
    fetchStats(1),   // cancelled as side effect — onCancel() fires, no error reported
])->catch(function (\Throwable $e) {
    // $e is DatabaseException — exactly one error, from exactly one source
    // The two cancellations are invisible here because they were not failures
    echo $e->getMessage();
});
```

The cancelled state is not a convenience feature. It is the foundation that
everything in the structured concurrency model is built on.

---

## Basic Usage

### Creating a Promise
```php
use Hibla\Promise\Promise;

$promise = new Promise(function (callable $resolve, callable $reject) {
    $resolve('Hello, World!');
});

$promise->then(function (string $value) {
    echo $value; // Hello, World!
});
```

Any exception thrown inside the executor is automatically caught and
transitions the promise to rejected:
```php
$promise = new Promise(function (callable $resolve, callable $reject) {
    throw new \RuntimeException('Something went wrong');
});

$promise->catch(function (\Throwable $e) {
    echo $e->getMessage(); // Something went wrong
});
```

### Resolving and rejecting manually

If you need to resolve or reject from outside the executor (for example,
from an event callback), construct the promise without an executor and call
`resolve()` or `reject()` directly:
```php
$promise = new Promise();

Loop::addTimer(1.0, function () use ($promise) {
    $promise->resolve('Done after 1 second');
});

$promise->then(fn($value) => print($value));
```

### Pre-settled promises
```php
// Already fulfilled
$promise = Promise::resolved('immediate value');

// Already rejected
$promise = Promise::rejected(new \RuntimeException('Already failed'));
```

---

## The Deferred Pattern

The deferred pattern separates the **creation** of a promise from its
**resolution**. Instead of resolving inside an executor callback, you create
the promise in a pending state and call `resolve()` or `reject()` on it later
from outside, typically from an event callback.

This is the pattern used internally by `delay()` and
`Loop::addCurlRequest()`, and it is the primary way to bridge non-promise
async APIs (timers, streams, signals) into the promise world.
```php
$promise = new Promise(); // No executor — starts pending

Loop::addTimer(1.0, function () use ($promise) {
    $promise->resolve('Done after 1 second');
});

$promise->then(fn($value) => print($value));
```

### Always pair with `onCancel()`

Any deferred promise that wraps a real resource must register an `onCancel()`
handler to clean up that resource if the promise is cancelled before it
resolves. Without it, cancelling the promise only changes its state and the
underlying work keeps running.
```php
$promise = new Promise();

$timerId = Loop::addTimer(5.0, function () use ($promise) {
    $promise->resolve('Finished');
});

// Without this, cancelling $promise leaves the timer alive for 5 seconds
$promise->onCancel(function () use ($timerId) {
    Loop::cancelTimer($timerId);
});
```

This is exactly how `delay()` is implemented internally:
```php
function delay(float $seconds): PromiseInterface
{
    $promise = new Promise();

    $timerId = Loop::addTimer($seconds, function () use ($promise): void {
        $promise->resolve(null);
    });

    $promise->onCancel(function () use ($timerId): void {
        Loop::cancelTimer($timerId);
    });

    return $promise;
}
```

The same pattern applies to any resource (curl handles, stream watchers,
signals, or any other event-driven callback):
```php
// Wrapping a stream read into a promise
function readOnce($stream): PromiseInterface
{
    $promise = new Promise();

    $watcherId = Loop::addReadWatcher($stream, function ($stream) use ($promise, &$watcherId) {
        Loop::removeReadWatcher($watcherId);
        $promise->resolve(fread($stream, 4096));
    });

    $promise->onCancel(function () use (&$watcherId) {
        Loop::removeReadWatcher($watcherId);
    });

    return $promise;
}
```

---

## Chaining

`then()` returns a new promise that resolves with the return value of the
callback, enabling chains:
```php
Promise::resolved(1)
    ->then(fn($n) => $n + 1)      // 2
    ->then(fn($n) => $n * 10)     // 20
    ->then(fn($n) => print($n));  // prints 20
```

---

### `then()` always runs asynchronously via microtask

`then()` handlers are **never** called synchronously, even if the promise is
already resolved at the time `then()` is registered. Every handler is
dispatched through `Loop::microTask()` and will not execute until all
currently running synchronous code has finished and control returns to the
event loop.

This guarantee is known as the **Zalgo prevention** rule: a callback either
always runs asynchronously or always runs synchronously, never both depending
on timing. Without it, code that looks sequential can behave
non-deterministically depending on whether the promise happened to already be
settled.
```php
$promise = Promise::resolved('immediate');

$promise->then(fn($v) => print("B: $v\n")); // registered — but not called yet

print("A\n"); // runs first — we are still in synchronous code

// Output:
// A
// B: immediate   ← then() fires only after sync code finishes
```

This applies everywhere: inside timers, inside other `then()` handlers, and
even inside Fibers. Calling `resolve()` on a promise never immediately invokes
downstream handlers in the same stack frame:
```php
$promise = new Promise();

$promise->then(fn($v) => print("C: $v\n"));

print("A\n");
$promise->resolve('hello'); // resolve() called — but then() not invoked yet
print("B\n");               // still runs before the then() handler

// Output:
// A
// B
// C: hello
```

---

### Long chains do not overflow the stack

Because every `then()` handler runs as a separate microtask dispatched
through the event loop, the PHP call stack is never deeply nested regardless
of how long the chain is. Each handler runs in its own flat loop tick: the
event loop acts as a **trampoline**, bouncing between handlers without
accumulating stack frames.

This means you can chain thousands of `then()` calls without hitting PHP's
call stack limit:
```php
$promise = Promise::resolved(0);

for ($i = 0; $i < 10000; $i++) {
    $promise = $promise->then(fn($n) => $n + 1);
}

$promise->then(fn($n) => print("Final: $n\n")); // Final: 10000
// Stack depth at every handler: flat — always the same depth regardless
// of chain length
```

---

### `catch()` — handling rejection

`catch()` is shorthand for `then(null, $fn)`. It attaches a rejection handler
only and returns a new promise, making it a full participant in the chain rather
than a terminal sink.

Like `then()`, `catch()` always runs asynchronously via microtask. It is never
called synchronously even if the promise is already rejected when `catch()` is
registered.

#### Stopping vs recovering

Returning a value from `catch()` recovers the chain: the next `then()` receives
that value as if no rejection happened. Re-throwing keeps the chain rejected and
passes the error downstream:
```php
// Recovery — catch() returns a value, chain continues fulfilled
Promise::rejected(new \RuntimeException('Oops'))
    ->catch(fn($e) => 'recovered')
    ->then(fn($v)  => print("fulfilled: $v\n")); // fulfilled: recovered

// Re-throw — catch() throws, chain stays rejected
Promise::rejected(new \RuntimeException('Oops'))
    ->catch(function (\Throwable $e) {
        logger()->error($e->getMessage());
        throw $e; // re-throw — next catch() in the chain receives it
    })
    ->catch(fn($e) => print("still rejected: {$e->getMessage()}\n"));
```

#### Placement matters

`catch()` only handles rejections from promises above it in the chain. A
rejection that originates below a `catch()` bypasses it entirely:
```php
Promise::resolved('ok')
    ->catch(fn($e) => 'this never runs') // nothing to catch — promise above fulfilled
    ->then(fn($v)  => throw new \RuntimeException('thrown in then()'))
    ->catch(fn($e) => print("caught: {$e->getMessage()}\n")); // caught: thrown in then()
```

A single `catch()` at the end of a chain covers every rejection from any step
above it, which is the most common pattern:
```php
fetchUser(1)
    ->then(fn($user)     => fetchOrders($user->id))
    ->then(fn($orders)   => fetchInvoices($orders))
    ->then(fn($invoices) => processInvoices($invoices))
    ->catch(fn($e)       => logError($e)); // covers any failure in the chain above
```

#### `catch()` does not intercept cancellation

A cancelled promise never calls `then()` or `catch()` handlers. If you need
to run logic on cancellation, use `onCancel()` for resource cleanup or
`finally()` if the logic must run on all outcomes:
```php
$promise
    ->then(fn($v)  => processResult($v))
    ->catch(fn($e) => logError($e))      // never called on cancellation
    ->finally(fn() => closeConnection()); // always called — including cancellation
```

---

### Thenable interoperability

If a `then()` handler returns any object that has a `then` method (not just
a `Promise` instance), the library will treat it as a thenable and wait for
it to resolve before continuing the chain. This means you can return promises
from other libraries inside a `then()` handler and the chain will correctly
wait for them without any explicit wrapping:
```php
use React\Promise\Deferred;

$promise
    ->then(function ($value) {
        $deferred = new Deferred();

        Loop::addTimer(1.0, fn() => $deferred->resolve($value . ' from react'));

        // Return a ReactPHP promise — Hibla detects the then() method
        // and waits for it to settle before continuing the chain
        return $deferred->promise();
    })
    ->then(function ($result) {
        echo "Got: $result\n"; // Got: hello from react
    });
```

> **Note:** Thenable detection only applies to plain objects with a `then`
> method. Arrays and primitives are never treated as thenables regardless
> of their contents.

---

### Cancellation and thenable interoperability

When a `then()` handler returns a Hibla `PromiseInterface`, cancelling the
outer promise automatically propagates inward: the inner promise is cancelled
too, triggering its `onCancel()` handlers and freeing its resources:
```php
$outer = fetchUser(1)->then(fn($user) => fetchOrders($user->id));

// fetchOrders returns a Hibla PromiseInterface — cancellation propagates
$outer->cancel();
// fetchOrders promise is also cancelled, its onCancel() handlers run
```

This forwarding only works for `PromiseInterface` instances. When a `then()`
handler returns a **foreign thenable** (a ReactPHP promise, a Guzzle promise,
or any object with a `then()` method that is not a `PromiseInterface`),
cancelling the outer promise changes its state but does **not** cancel the
foreign thenable. The foreign promise keeps running:
```php
// Hibla PromiseInterface — cancellation propagates ✓
$outer = $promise->then(fn($v) => hiblaDerivedPromise($v));
$outer->cancel(); // inner promise also cancelled

// Foreign thenable — cancellation does NOT propagate ✗
$outer = $promise->then(fn($v) => $reactPhpPromise);
$outer->cancel(); // outer state changes, but $reactPhpPromise keeps running
```

If you need cancellation to propagate into a foreign promise, wrap it
manually with an `onCancel()` handler:
```php
$outer = $promise->then(function ($v) use (&$foreignPromise) {
    $foreignPromise = fetchWithReactPhp($v);
    return $foreignPromise;
});

$outer->onCancel(function () use (&$foreignPromise) {
    if ($foreignPromise !== null) {
        $foreignPromise->cancel();
    }
});
```

---

### Cyclic chain detection

If a `then()` handler returns the same promise it belongs to, resolving that
promise would cause it to wait on itself forever in a deadlock. The library
detects this and immediately rejects the promise with a `TypeError` rather
than hanging:
```php
$promise = new Promise();

$chained = $promise->then(function () use (&$chained) {
    return $chained; // returns itself — cyclic chain
});

$promise->resolve('value');

$chained->catch(function (\TypeError $e) {
    echo $e->getMessage(); // "Chaining cycle detected"
});
```

---

### `finally()` — always runs

`finally()` runs on all outcomes: fulfilled, rejected, and cancelled. Use it
for cleanup that must always happen regardless of result:
```php
$promise
    ->then(fn($data) => processData($data))
    ->catch(fn($e)   => logError($e))
    ->finally(fn()   => closeConnection());
```

> **Timing note:** On fulfillment and rejection, `finally()` runs
> asynchronously via microtask, the same as `then()`. On cancellation, it runs
> **synchronously** to ensure immediate resource cleanup before the cancel
> call returns.

---

## Blocking — `wait()`

`wait()` returns immediately if the promise is already settled. If the promise
is still pending, it drives the event loop (running full iterations via
`Loop::runOnce()`) until the promise settles, then returns the resolved value.

The script cannot advance past the `wait()` line until it returns, but the
event loop is fully alive underneath: timers fire, I/O callbacks run, and
other in-flight work continues normally on every iteration while it waits:
```php
Loop::addPeriodicTimer(1.0, fn() => print("tick\n"), maxExecutions: 5);

delay(5)->then(fn() => print("5 seconds passed\n"))->wait();

echo "Done\n";

// Output:
// tick          ← periodic timer fires normally during wait()
// tick
// tick
// tick
// tick
// 5 seconds passed
// Done          ← script advances only after wait() returns
```

`wait()` throws if the promise rejects or is cancelled, whether that happened
before `wait()` was called or during the loop iterations while waiting:
```php
try {
    Promise::rejected(new \RuntimeException('Failed'))->wait();
} catch (\RuntimeException $e) {
    echo $e->getMessage(); // Failed
}
```
```php
try {
    $promise = new Promise();
    Loop::addTimer(1.0, fn() => $promise->cancel());
    $promise->wait(); // promise is cancelled during the loop iterations
} catch (CancelledException $e) {
    echo $e->getMessage(); // Promise was cancelled during wait
}
```

> **Important:** `wait()` **cannot** be called inside a Fiber. Doing so
> throws `InvalidContextException` with the exact file and line of the
> offending call. Inside a Fiber, use `await()` from `hiblaphp/async`
> to properly suspend the fiber instead of blocking the thread.

---

## Async Delay

The global `delay()` function returns a promise that resolves after the
given number of seconds. It is cancellable: cancelling it also cancels
the underlying timer.
```php
use function Hibla\delay;

delay(1.5)->then(fn() => print("1.5 seconds later\n"));
```

---

## Cancellation

### The 4-state model

This library extends the standard Promise/A+ 3-state model with a distinct
**cancelled** state. Cancellation is not an error; it represents a deliberate
decision to abort work that is no longer needed. A cancelled promise never
transitions to fulfilled or rejected.
```php
$promise = new Promise(function ($resolve) {
    Loop::addTimer(10.0, fn() => $resolve('done'));
});

$promise->cancel();

var_dump($promise->isCancelled()); // true
var_dump($promise->isRejected());  // false
```

### Cancellation is synchronous

Cancellation runs **synchronously** and **immediately** when `cancel()` is
called. All registered `onCancel()` handlers execute in registration order
before `cancel()` returns. This is intentional: it eliminates race conditions
where the promise could be resolved or rejected in the same tick that
cancellation is requested.
```php
$promise = new Promise();
$promise->onCancel(function () {
    echo "A\n"; // prints first
});
$promise->onCancel(function () {
    echo "B\n"; // prints second
});

$promise->cancel();
echo "C\n"; // prints third — after both handlers have already run
```

### Cooperative cancellation — `onCancel()`

Calling `cancel()` on a promise only changes its state. It does **not**
automatically stop the underlying work. To actually free resources (cancel
a timer, abort an HTTP request, close a file handle), you must register an
`onCancel()` handler at the point where the async work begins.
```php
// Correct — resources are freed on cancellation
$promise = new Promise(function ($resolve) use (&$timerId) {
    $timerId = Loop::addTimer(10.0, fn() => $resolve('done'));
});

$promise->onCancel(function () use (&$timerId) {
    Loop::cancelTimer($timerId); // Actually stops the timer
});

$promise->cancel(); // Timer is cancelled, no callback fires
```
```php
// Incorrect — timer keeps running after cancel()
$promise = new Promise(function ($resolve) {
    Loop::addTimer(10.0, fn() => $resolve('done'));
    // No onCancel registered — cancelling changes state but not the timer
});

$promise->cancel(); // Promise state changes, but timer is still live
```

Multiple `onCancel()` handlers can be registered and they fire in registration
order. If cancellation has already occurred when `onCancel()` is called, the
handler fires immediately and synchronously.

### `onCancel()` handlers must not throw

Because cancellation is synchronous, `onCancel()` handlers run directly on
the call stack of whatever code called `cancel()`. If a handler throws, the
exception propagates out of `cancel()`, meaning code that calls
`cancel()` expecting it to be a silent fire-and-forget operation will receive
an unexpected exception.

The library collects exceptions from all handlers before rethrowing, so every
handler runs regardless of whether a previous one threw. The throwing behavior
depends on how many handlers failed:
```
One handler throws      -> that exception is rethrown directly from cancel()
Multiple handlers throw -> all exceptions are wrapped in AggregateErrorException
```
```php
$promise->onCancel(function () {
    throw new \RuntimeException('cleanup failed');
});

// cancel() propagates the exception — not a silent no-op
try {
    $promise->cancel();
} catch (\RuntimeException $e) {
    echo $e->getMessage(); // "cleanup failed"
}
```

The promise is already in the `cancelled` state before any handler runs,
even when a handler throws. Cleanup of internal state runs regardless of
handler exceptions. The only consequence of a throwing handler is the
exception propagating out of `cancel()`.
```php
$promise->onCancel(function () {
    throw new \RuntimeException('handler A failed');
});

$promise->onCancel(function () {
    echo "handler B ran\n"; // always runs — all handlers execute
});

try {
    $promise->cancel();
} catch (\RuntimeException $e) {
    echo $e->getMessage(); // "handler A failed"
}

var_dump($promise->isCancelled()); // true — state is cancelled regardless
```

Keep handlers to simple, exception-safe cleanup operations. If a cleanup
operation can fail, wrap it internally:
```php
// Wrong — exception leaks out of cancel()
$promise->onCancel(function () use ($conn) {
    $conn->close(); // may throw
});

// Correct — failures are contained
$promise->onCancel(function () use ($conn) {
    try {
        $conn->close();
    } catch (\Throwable) {
        // log if needed, but never let it propagate
    }
});
```

For cleanup that is inherently async (such as sending a cancellation signal
to a remote server), fire a task and return immediately rather than awaiting
the result:
```php
$promise->onCancel(function () use ($requestId) {
    // Correct: fire and return immediately
    Loop::addCurlRequest(
        "https://api.example.com/cancel/$requestId",
        [],
        fn() => null
    );

    // Wrong: do not await or block inside onCancel
    // Http::delete("https://api.example.com/cancel/$requestId")->wait();
});
```

### `cancel()` vs `cancelChain()`

**`cancel()`** propagates forward only: it cancels the promise and all child
promises created from it via `then()`, but does not propagate upward to the
parent:
```php
$root = downloadFile($url);
$processed = $root->then(fn($file) => processFile($file));

$root->cancel(); // Cancels root AND processed (forward propagation)
```

**`cancelChain()`** propagates both ways: it walks up the parent chain to
find the root, then cancels everything from the root downward. Use this when
you only hold a reference to a child promise but need to cancel the entire
operation:
```php
$processed = downloadFile($url)->then(fn($file) => processFile($file));

// Don't have $root? cancelChain() finds it for you.
$processed->cancelChain(); // Cancels download AND processed
```

### The cost of non-cooperative cancellation

The difference between registering an `onCancel()` handler and not
registering one is not just architectural; it has a direct, measurable
impact on how long your script runs.
```php
// No onCancel() handler — cancelling changes state but not the timer
function nonCancellableDelay(float $seconds): PromiseInterface
{
    return new Promise(function (callable $resolve) use ($seconds) {
        Loop::addTimer($seconds, function () use ($resolve) {
            $resolve();
        });
    });
}

// onCancel() handler registered — cancelling also cancels the timer
function cancellableDelay(float $seconds): PromiseInterface
{
    $promise = new Promise();

    $timerId = Loop::addTimer($seconds, function () use ($promise) {
        $promise->resolve();
    });

    $promise->onCancel(function () use ($timerId) {
        Loop::cancelTimer($timerId);
    });

    return $promise;
}

$start = microtime(true);
$promise = nonCancellableDelay(5);
Loop::addTimer(1, $promise->cancel(...));
Loop::run();
echo 'Non-cancellable: ' . round(microtime(true) - $start, 2) . 's' . PHP_EOL;
// Non-cancellable: 5.01s — loop waited for the timer even though the
// promise was cancelled

$start = microtime(true);
$promise = cancellableDelay(5);
Loop::addTimer(1, $promise->cancel(...));
Loop::run();
echo 'Cancellable: ' . round(microtime(true) - $start, 2) . 's' . PHP_EOL;
// Cancellable: 1.00s — timer was cancelled immediately, loop exited cleanly
```

`cancel()` changes the promise state. `onCancel()` is what actually stops
the work. Both are required for cancellation to be meaningful.

---

## `SettledResult`

`SettledResult` is the value object returned by `allSettled()`,
`concurrentSettled()`, `batchSettled()`, `mapSettled()`, and
`forEachSettled()`. It is a readonly final class with three possible states.
```php
$result->isFulfilled(); // bool
$result->isRejected();  // bool
$result->isCancelled(); // bool

$result->value;  // mixed — only meaningful when isFulfilled()
$result->reason; // mixed — only meaningful when isRejected()
```

`SettledResult` implements `JsonSerializable`:
```php
json_encode($result);
// {"status":"fulfilled","value":"..."}
// {"status":"rejected","reason":{"message":"...","class":"...","file":"...","line":...}}
// {"status":"cancelled"}
```

---

## Accepting iterables

All collection methods accept any `iterable`: arrays, generators, or any
`Traversable`.
```php
// Array
Promise::all([$promise1, $promise2, $promise3]);

// Generator
Promise::all((function () {
    yield 'users'  => fetchUsers();
    yield 'orders' => fetchOrders();
    yield 'stats'  => fetchStats();
})());

// Any Traversable
Promise::all(new ArrayIterator([$promise1, $promise2]));
```

> **Note:** Collection methods call `iterator_to_array()` internally to
> materialize the iterable before processing. This means all promises are
> already running by the time the method sees them. If you need lazy start
> with concurrency control, use the Concurrency Utilities instead.

All collection methods must be called on the `Promise` class, not on promise
instances:
```php
// Correct
Promise::all([$promise1, $promise2]);

// Wrong — do not call static methods on instances
$promise->all([$promise1, $promise2]);
```

---

## Structured Concurrency

The non-settled collection methods (`all()`, `race()`, `any()`, and
`timeout()`) follow **structured concurrency** semantics. The lifetime of
every promise inside the collection is bound to the collection promise that
owns them. The library enforces this in three situations:

**1. A promise rejects:**
The collection promise rejects immediately with that reason. All other
promises that are still pending are automatically cancelled. Their
`onCancel()` handlers fire synchronously, freeing any resources they hold.

**2. A promise is cancelled:**
The collection promise treats this identically to a rejection: it rejects
immediately and all remaining pending promises are automatically cancelled,
triggering their `onCancel()` handlers synchronously.

**3. The collection promise itself is cancelled:**
Cancellation propagates inward. All pending promises inside the collection
are automatically cancelled synchronously, triggering their `onCancel()`
handlers. The collection promise transitions to cancelled.
```php
$userPromise  = fetchUser(1);
$orderPromise = fetchOrders(1);
$statsPromise = fetchStats(1);

$userPromise->onCancel(fn()  => closeUserConnection());
$orderPromise->onCancel(fn() => closeOrderConnection());
$statsPromise->onCancel(fn() => closeStatsConnection());

$all = Promise::all([$userPromise, $orderPromise, $statsPromise]);

// Scenario 1: fetchUser rejects
// -> $orderPromise and $statsPromise are auto-cancelled synchronously
// -> their onCancel() handlers run immediately
// -> $all rejects with the user fetch error

// Scenario 2: $orderPromise is cancelled externally
// -> $all rejects with CancelledException
// -> $userPromise and $statsPromise are auto-cancelled synchronously
// -> their onCancel() handlers run immediately

// Scenario 3: $all itself is cancelled
// -> all three promises are auto-cancelled synchronously
// -> all three onCancel() handlers run before cancel() returns
$all->cancel();
```

The `*Settled` variants (`allSettled()`, `concurrentSettled()`,
`batchSettled()`, `mapSettled()`, and `forEachSettled()`) deliberately opt
out of this behavior. Individual failures and cancellations are captured as
`SettledResult` objects rather than propagating. However, cancelling the
collection promise itself still cancels all in-flight operations even in
settled variants.

---

## `Promise::all()`

Resolves with an array of all results when every promise fulfills, preserving
the original key order. **Fail-fast**: the moment any promise rejects or is
cancelled, the collection rejects immediately and all remaining pending
promises are automatically cancelled synchronously.
```php
Promise::all([
    'user'   => fetchUser(1),
    'orders' => fetchOrders(1),
    'stats'  => fetchStats(1),
])->then(function (array $results) {
    $user   = $results['user'];
    $orders = $results['orders'];
    $stats  = $results['stats'];
})->catch(function (\Throwable $e) {
    // One rejected or was cancelled
    // Remaining pending promises were automatically cancelled synchronously
    echo "Failed: " . $e->getMessage();
});
```

**When to use:** When you need all results and a single failure makes the
entire operation meaningless. Use `allSettled()` if partial results are
acceptable.

---

## `Promise::allSettled()`

Waits for **every** promise to settle regardless of outcome. **Never rejects,
never cancels siblings.** Returns an array of `SettledResult` objects in the
original key order. Only external cancellation of the collection promise
itself propagates inward.
```php
Promise::allSettled([
    'primary'   => fetchFromPrimary(),
    'secondary' => fetchFromSecondary(),
    'fallback'  => fetchFromFallback(),
])->then(function (array $results) {
    foreach ($results as $key => $result) {
        if ($result->isFulfilled()) {
            echo "$key succeeded: " . json_encode($result->value) . "\n";
        } elseif ($result->isRejected()) {
            echo "$key failed: " . $result->reason->getMessage() . "\n";
        } elseif ($result->isCancelled()) {
            echo "$key was cancelled\n";
        }
    }
});
```

**When to use:** Batch operations where you want to process all outcomes:
sending notifications, syncing records, or any scenario where partial failure
is acceptable and you need a full audit of results.

---

## `Promise::race()`

Settles with the **first promise to settle**, whether fulfilled or rejected.
The moment any promise settles, all remaining pending promises are
automatically cancelled synchronously before the collection's `then()` or
`catch()` fires.
```php
Promise::race([
    fetchFromRegionA(),
    fetchFromRegionB(),
    fetchFromRegionC(),
])->then(function ($result) {
    // Fastest settled — the other two were already cancelled synchronously
    echo "Fastest result: $result\n";
})->catch(function (\Throwable $e) {
    // The first to settle rejected or was cancelled
    // All others were already cancelled synchronously
});
```

> **Note:** `race()` rejects if the first promise to settle rejects, even
> if others would eventually succeed. Use `any()` if you want the first to
> **fulfill** instead.

**When to use:** Redundant requests to multiple sources where only the
fastest matters, or implementing client-side load balancing.

---

## `Promise::any()`

Resolves with the **first promise to fulfill**. Rejections are ignored unless
every promise rejects or is cancelled, in which case it rejects with an
`AggregateErrorException` containing all failure reasons.
```php
Promise::any([
    tryPrimaryDatabase(),
    tryReplicaDatabase(),
    tryFallbackDatabase(),
])->then(function ($result) {
    echo "Got data: " . json_encode($result) . "\n";
})->catch(function (\Hibla\Promise\Exceptions\AggregateErrorException $e) {
    // Every single promise rejected or was cancelled
    foreach ($e->getErrors() as $index => $error) {
        echo "Source $index: " . $error->getMessage() . "\n";
    }
});
```

**When to use:** Fault-tolerant operations where you want to try multiple
sources and succeed as long as at least one works.

---

## `Promise::timeout()`

Wraps any promise with a deadline. If the timeout fires first, the original
promise is cancelled synchronously and the collection rejects with
`TimeoutException`. If the original promise settles first, the internal delay
timer is cancelled cleanly.
```php
$query = slowDatabaseQuery();
$query->onCancel(fn() => cancelQuery());

Promise::timeout($query, seconds: 5.0)
    ->then(fn($result) => processResult($result))
    ->catch(function (\Throwable $e) {
        if ($e instanceof \Hibla\Promise\Exceptions\TimeoutException) {
            echo "Query timed out — query was cancelled\n";
        } else {
            echo "Query failed: " . $e->getMessage() . "\n";
        }
    });
```

**When to use:** Any operation that must not run indefinitely: database
queries, HTTP requests, user input, or any external I/O.

---

## Combinator behavior summary

| Method         | Rejects on member rejection? | Rejects on member cancel? | Cancels pending synchronously? | Cancels on collection cancel? | Returns                            |
| -------------- | ---------------------------- | ------------------------- | ------------------------------ | ----------------------------- | ---------------------------------- |
| `all()`        | Yes                          | Yes                       | Yes                            | Yes                           | `array<results>`                   |
| `allSettled()` | Captures as SettledResult    | Captures as SettledResult | Never                          | Yes, synchronously            | `array<SettledResult>`             |
| `race()`       | Yes, if first to settle      | Yes, if first to settle   | Yes, on any settle             | Yes, synchronously            | First settled value                |
| `any()`        | Yes, only if all reject      | Treated as rejection      | Yes, on first fulfill          | Yes, synchronously            | First fulfilled value              |
| `timeout()`    | Yes                          | Yes                       | Yes, synchronously             | Yes, synchronously            | Original value or TimeoutException |

---

## Concurrency Utilities

All concurrency methods accept **callables that return promises**, not
pre-created promise instances. This is fundamental: a pre-created promise is
already running and cannot be subject to concurrency control. Callables give
the library control over exactly when each task starts. Doing so will throw an exception.
```php
// Correct — factory callables, tasks start when the library decides
$tasks = [
    fn() => fetchUser(1),
    fn() => fetchUser(2),
    fn() => fetchUser(3),
];

// Incorrect — all three are already running before concurrent() sees them
$tasks = [
    fetchUser(1),
    fetchUser(2),
    fetchUser(3),
];
```
> **Note**: items in concurrency collection must be a callable that return a promise. If >   pass by an unexpected value will throw a `RuntimeException`.

### Accepting iterables

All concurrency utilities accept any `iterable`: arrays, generators, or any
`Traversable`. Tasks are pulled **lazily** from the iterable, so generators
are never fully materialized. Only as many tasks are pulled as needed to fill
the concurrency slots, making generators ideal for very large or infinite task
sources where you do not want to load everything into memory at once.

> **New to generators?** All examples below use plain arrays, which work
> identically. Swap in a generator only when you need to process a very large
> dataset lazily.

---

### Structured concurrency in concurrency utilities

The non-settled concurrency methods follow the same structured concurrency
semantics as the collection methods:

**1. A task rejects:** The collection rejects immediately. All currently
in-flight tasks are automatically cancelled synchronously before the
collection's `catch()` fires. No further tasks are pulled from the iterator.

**2. A task promise is cancelled externally:** Treated identically to a
rejection: the collection rejects with `CancelledException` and all
remaining in-flight tasks are cancelled synchronously.

**3. The collection promise itself is cancelled:** All currently running tasks
are immediately cancelled synchronously. No further tasks are pulled from
the iterator.

The `*Settled` variants capture individual failures as `SettledResult` and
continue processing. However, cancelling the collection promise itself still
cancels all in-flight tasks synchronously.

---

### `Promise::concurrent()`

Executes tasks in parallel up to the concurrency limit. Results are returned
in the **original key order** regardless of completion order. **Fail-fast.**
```php
$tasks = [
    'alice' => fn() => fetchUser('alice'),
    'bob'   => fn() => fetchUser('bob'),
    'carol' => fn() => fetchUser('carol'),
];

Promise::concurrent($tasks, concurrency: 2)
    ->then(fn(array $results) => print(count($results) . " users fetched\n"))
    ->catch(fn($e) => print("Aborted: " . $e->getMessage()));
```

> **Large datasets:** Use a generator to avoid loading everything into memory
> at once. Tasks are pulled lazily as slots open up:
> ```php
> Promise::concurrent((function () use ($db) {
>     foreach ($db->cursor('SELECT id FROM records') as $row) {
>         yield $row['id'] => fn() => processRecord($row['id']);
>     }
> })(), concurrency: 10);
> ```

---

### `Promise::concurrentSettled()`

Identical scheduling to `concurrent()`: the pool is kept full and tasks start
as slots open. Individual failures and cancellations are captured as
`SettledResult` rather than aborting. External cancellation of the collection
still cancels all in-flight tasks synchronously.
```php
$tasks = [
    'alice' => fn() => syncUser('alice'),
    'bob'   => fn() => syncUser('bob'),
    'carol' => fn() => syncUser('carol'),
];

Promise::concurrentSettled($tasks, concurrency: 2)
    ->then(function (array $results) {
        $succeeded = array_filter($results, fn($r) => $r->isFulfilled());
        $failed    = array_filter($results, fn($r) => $r->isRejected());
        $cancelled = array_filter($results, fn($r) => $r->isCancelled());

        echo count($succeeded) . " synced, "
           . count($failed)    . " failed, "
           . count($cancelled) . " cancelled\n";
    });
```

---

### `Promise::batch()`

Processes tasks in sequential batches. The entire first batch must complete
before the second batch starts. **Fail-fast**: if any task in a batch fails,
all in-flight tasks in that batch are cancelled synchronously and no further
batches start.
```php
$emails = [
    'alice@example.com' => fn() => sendEmail('alice@example.com'),
    'bob@example.com'   => fn() => sendEmail('bob@example.com'),
    'carol@example.com' => fn() => sendEmail('carol@example.com'),
    'dave@example.com'  => fn() => sendEmail('dave@example.com'),
    'eve@example.com'   => fn() => sendEmail('eve@example.com'),
];

Promise::batch($emails, batchSize: 2, concurrency: 2)
    ->then(fn(array $results) => print(count($results) . " emails sent\n"));
```

**When to use `batch()` over `concurrent()`:** When you need hard boundaries
between groups of work, for example processing records page by page where
each page must fully commit before the next starts.

---

### `Promise::batchSettled()`

Same batching behavior as `batch()` but individual failures are captured as
`SettledResult`. Always resolves once all batches are attempted.
```php
$records = [
    1 => fn() => importRecord(1),
    2 => fn() => importRecord(2),
    3 => fn() => importRecord(3),
    4 => fn() => importRecord(4),
    5 => fn() => importRecord(5),
];

Promise::batchSettled($records, batchSize: 2, concurrency: 2)
    ->then(function (array $results) {
        $failed = array_filter($results, fn($r) => $r->isRejected());
        if (count($failed) > 0) {
            retryFailed($failed);
        }
    });
```

---

### `Promise::map()`

Transforms each item using an async mapper. Input items can be plain values
or promises. Preserves **original key order**. Defaults to **unlimited
concurrency**. **Fail-fast.**
```php
$ids = [1 => 1, 2 => 2, 3 => 3];

Promise::map($ids, fn($id) => fetchUser($id), concurrency: 2)
    ->then(fn(array $users) => processAll($users));
```

---

### `Promise::mapSettled()`

Same as `map()` but captures each outcome as `SettledResult`. External
cancellation still cancels all in-flight mappers synchronously.
```php
$records = [1 => $record1, 2 => $record2, 3 => $record3];

Promise::mapSettled($records, fn($record) => processRecord($record), concurrency: 2)
    ->then(function (array $results) {
        $processed = array_filter($results, fn($r) => $r->isFulfilled());
        $skipped   = array_filter($results, fn($r) => $r->isRejected());
        echo count($processed) . " processed, " . count($skipped) . " skipped\n";
    });
```

---

### `Promise::filter()`

Tests each item against an async predicate, returning only items where the
predicate resolves to `true`. Preserves both **key order** and **original
keys**. Defaults to **unlimited concurrency**. **Fail-fast.**
```php
$products = [
    'sku-1' => $product1,
    'sku-2' => $product2,
    'sku-3' => $product3,
];

Promise::filter($products, fn($product) => checkInStock($product), concurrency: 2)
    ->then(fn(array $inStock) => print(count($inStock) . " available\n"));
```

Treat predicate failures as non-passing rather than aborting:
```php
Promise::filter($products, function ($product) {
    return validate($product)
        ->catch(fn() => false); // validation failure = excluded, not aborted
});
```

---

### `Promise::reduce()`

The only inherently **sequential** method: each step waits for the previous
carry before starting. **Fail-fast.**
```php
Promise::reduce(
    [1, 2, 3, 4, 5],
    fn($carry, $n) => Promise::resolved($carry + $n),
    initial: 0
)->then(fn($sum) => print("Sum: $sum\n")); // Sum: 15
```

**When to use:** When each step genuinely depends on the result of the
previous step. If steps are independent, `map()` with concurrency is always
faster.

---

### `Promise::forEach()`

Executes a side-effect callback for each item. Return values are discarded
immediately, so memory stays flat regardless of input size. Defaults to
**unlimited concurrency**. **Fail-fast.**
```php
$records = [$record1, $record2, $record3];

Promise::forEach($records, fn($record) => saveToExternalApi($record), concurrency: 2)
    ->then(fn() => print("All records processed\n"))
    ->catch(fn($e) => print("Aborted: " . $e->getMessage()));
```

---

### `Promise::forEachSettled()`

Same as `forEach()` (side effects only, memory stays flat) but failures are
silently swallowed. Every item is attempted regardless of individual failures.
```php
$users = [$user1, $user2, $user3];

Promise::forEachSettled($users, fn($user) => sendWelcomeEmail($user), concurrency: 2)
    ->then(fn() => print("All sends attempted\n"));
```

**When to use `forEachSettled()` over `forEach()`:** Notifications, emails,
webhooks, audit logs, or cache invalidation, where partial failure is
acceptable and every item should be attempted regardless.

---

### Concurrency utility summary

| Method                | Accepts iterable | Tasks pulled lazily | Default concurrency | Fail-fast | Cancels in-flight on reject? | Cancels on collection cancel? | Returns                |
| --------------------- | ---------------- | ------------------- | ------------------- | --------- | ---------------------------- | ----------------------------- | ---------------------- |
| `concurrent()`        | Yes              | Yes                 | Required            | Yes       | Yes, synchronously           | Yes, synchronously            | `array<results>`       |
| `concurrentSettled()` | Yes              | Yes                 | Required            | No        | Captures as SettledResult    | Yes, synchronously            | `array<SettledResult>` |
| `batch()`             | Yes              | Yes, per batch      | batchSize           | Yes       | Yes, synchronously           | Yes, synchronously            | `array<results>`       |
| `batchSettled()`      | Yes              | Yes, per batch      | batchSize           | No        | Captures as SettledResult    | Yes, synchronously            | `array<SettledResult>` |
| `map()`               | Yes              | Yes                 | Unlimited           | Yes       | Yes, synchronously           | Yes, synchronously            | `array<mapped>`        |
| `mapSettled()`        | Yes              | Yes                 | Unlimited           | No        | Captures as SettledResult    | Yes, synchronously            | `array<SettledResult>` |
| `filter()`            | Yes              | Yes                 | Unlimited           | Yes       | Yes, synchronously           | Yes, synchronously            | `array<filtered>`      |
| `reduce()`            | Yes              | Yes                 | Sequential          | Yes       | N/A                          | Yes, synchronously            | Single value           |
| `forEach()`           | Yes              | Yes                 | Unlimited           | Yes       | Yes, synchronously           | Yes, synchronously            | void                   |
| `forEachSettled()`    | Yes              | Yes                 | Unlimited           | No        | Captures as SettledResult    | Yes, synchronously            | void                   |

---

## Unhandled Rejections

If a promise is rejected and no `catch()` or rejection handler is ever
attached, the rejection reason is **thrown as an exception** when the promise
is garbage collected. This mirrors **Node.js v15+ behavior**, where unhandled
rejections crash the process rather than printing a warning.
```
Fatal error: Uncaught RuntimeException: Something went wrong
in /path/to/file.php:42
Stack trace:
#0 ...
```

You can install a global handler to customize this behavior:
```php
use Hibla\Promise\Promise;

Promise::setRejectionHandler(function (mixed $reason, $promise) {
    logger()->error('Unhandled rejection', ['reason' => $reason]);
});

// Restore default throw behavior
Promise::setRejectionHandler(null);
```

This maps to the Node.js equivalent:
```
PHP                                         Node.js
────────────────────────────────────────────────────────────────
__destruct() throws                         process crashes (v15+)
Promise::setRejectionHandler($fn)           process.on('unhandledRejection', fn)
Promise::setRejectionHandler(null)          removes the handler, restores default
```

### What silences unhandled rejection tracking

There are two ways an unhandled rejection throw is suppressed. Only one of
them is intentional:

**Intentional: attaching a rejection handler:**
```php
$promise->catch(fn($e) => logError($e));       // intentional
$promise->then(null, fn($e) => logError($e));  // same
```

**Unintentional: inspecting the promise value:**

Calling `getValue()`, `getReason()`, or `wait()` on a promise sets an
internal `valueAccessed` flag. Once this flag is set, `__destruct()` skips
the unhandled rejection check entirely, even if no `catch()` was ever
attached.
```php
$promise = Promise::rejected(new \RuntimeException('Something went wrong'));

// Inspecting the reason marks the promise as "accessed"
$reason = $promise->getReason(); // sets valueAccessed = true

// No catch() attached — but the exception is never thrown on destruct.
// The rejection is silently swallowed.
```

This means debugging missing unhandled rejection errors can be non-obvious. If
you are inspecting a promise for logging or diagnostics and still want
unhandled rejection tracking to work, attach a `catch()` explicitly:
```php
// Wrong — inspection silences the throw
if ($promise->isRejected()) {
    $reason = $promise->getReason();
    // Exception never thrown on destruct — rejection is silent
}

// Correct — attach a handler to keep tracking active
$promise->catch(function (\Throwable $e) {
    logger()->error($e->getMessage());
});
```

The same applies to `wait()`: calling it on a rejected promise throws the
rejection reason and marks the promise as accessed, silencing the
`__destruct()` check. This is intentional for `wait()` since you are
explicitly handling the rejection via the thrown exception. It is only a
footgun when using `getValue()` or `getReason()` for inspection rather than
for handling.
```
Method          Sets hasRejectionHandler   Sets valueAccessed   Silences throw?
catch()         Yes                        No                   Yes, intentionally
then(null, fn)  Yes                        No                   Yes, intentionally
getValue()      No                         Yes                  Yes, side effect
getReason()     No                         Yes                  Yes, side effect
wait()          No                         Yes                  Yes, intentional
```

---

## API Reference

### Instance Methods

| Method                                                | Description                                                                                                          |
| ----------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| `then(?callable $onFulfilled, ?callable $onRejected)` | Attach fulfillment and/or rejection handlers. Returns a new promise.                                                 |
| `catch(callable $onRejected)`                         | Attach a rejection handler only. Equivalent to `then(null, $fn)`.                                                    |
| `finally(callable $onFinally)`                        | Runs on all outcomes. Async on fulfill/reject, synchronous on cancel.                                                |
| `cancel()`                                            | Cancel the promise synchronously. Runs onCancel() handlers, then cancels child promises. May throw if a handler throws. |
| `cancelChain()`                                       | Walk up to the root promise and cancel the entire chain synchronously.                                               |
| `onCancel(callable $handler)`                         | Register a synchronous cleanup handler. Must be fast and must not throw.                                             |
| `wait()`                                              | Block synchronously until the promise settles. Throws on rejection or cancellation. Cannot be called inside a Fiber. |
| `resolve(mixed $value)`                               | Fulfill the promise with a value. No-op if already settled.                                                          |
| `reject(mixed $reason)`                               | Reject the promise with a reason. No-op if already settled.                                                          |
| `isCancelled(): bool`                                 | True if `cancel()` was called.                                                                                       |
| `isFulfilled(): bool`                                 | True if resolved with a value.                                                                                       |
| `isRejected(): bool`                                  | True if rejected with a reason.                                                                                      |
| `isPending(): bool`                                   | True if neither settled nor cancelled.                                                                               |
| `isSettled(): bool`                                   | True if fulfilled or rejected (not pending, not cancelled).                                                          |
| `getValue(): mixed`                                   | Returns the resolved value. Null if not fulfilled. Sets valueAccessed — see unhandled rejection tracking.            |
| `getReason(): mixed`                                  | Returns the rejection reason. Null if not rejected. Sets valueAccessed — see unhandled rejection tracking.           |
| `getState(): string`                                  | Returns `'pending'`, `'fulfilled'`, `'rejected'`, or `'cancelled'`.                                                  |

### Static Methods

| Method                                                                            | Description                                                                                        |
| --------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| `Promise::resolved(mixed $value)`                                                 | Create an already-fulfilled promise.                                                               |
| `Promise::rejected(mixed $reason)`                                                | Create an already-rejected promise.                                                                |
| `Promise::all(iterable $promises)`                                                | Resolve when all resolve. Fail-fast, cancels pending synchronously on rejection.                   |
| `Promise::allSettled(iterable $promises)`                                         | Wait for all to settle. Never rejects. Returns `SettledResult[]`.                                  |
| `Promise::race(iterable $promises)`                                               | Settle with the first promise to settle. Cancels losers synchronously.                             |
| `Promise::any(iterable $promises)`                                                | Resolve with the first to fulfill. Rejects with `AggregateErrorException` if all reject.           |
| `Promise::timeout(PromiseInterface $promise, float $seconds)`                     | Reject with `TimeoutException` if not settled in time. Cancels promise synchronously on timeout.   |
| `Promise::concurrent(iterable $tasks, int $concurrency)`                          | Run callable tasks in parallel with a concurrency cap. Fail-fast, cancels in-flight synchronously. |
| `Promise::concurrentSettled(iterable $tasks, int $concurrency)`                   | Same as `concurrent()` but never rejects. Returns `SettledResult[]`.                               |
| `Promise::batch(iterable $tasks, int $batchSize, ?int $concurrency)`              | Process tasks in sequential batches. Fail-fast per batch.                                          |
| `Promise::batchSettled(iterable $tasks, int $batchSize, ?int $concurrency)`       | Same as `batch()` but never rejects. Returns `SettledResult[]`.                                    |
| `Promise::map(iterable $items, callable $mapper, ?int $concurrency)`              | Transform each item with an async mapper. Fail-fast, cancels in-flight synchronously.              |
| `Promise::mapSettled(iterable $items, callable $mapper, ?int $concurrency)`       | Same as `map()` but never rejects. Returns `SettledResult[]`.                                      |
| `Promise::filter(iterable $items, callable $predicate, ?int $concurrency)`        | Filter items using an async predicate. Fail-fast, cancels in-flight synchronously.                 |
| `Promise::reduce(iterable $items, callable $reducer, mixed $initial)`             | Sequential async reduce. Fail-fast.                                                                |
| `Promise::forEach(iterable $items, callable $callback, ?int $concurrency)`        | Side-effect per item, no result accumulation. Fail-fast, cancels in-flight synchronously.          |
| `Promise::forEachSettled(iterable $items, callable $callback, ?int $concurrency)` | Same as `forEach()` but never rejects.                                                             |
| `Promise::setRejectionHandler(?callable $handler)`                                | Set a global unhandled rejection handler. Returns previous handler.                                |

### Global Functions

| Function                                        | Description                                                        |
| ----------------------------------------------- | ------------------------------------------------------------------ |
| `Hibla\delay(float $seconds)`                   | Returns a cancellable promise that resolves after the given delay. |
| `Hibla\setRejectionHandler(?callable $handler)` | Alias for `Promise::setRejectionHandler()`.                        |

---

## PHPStan Integration

Hibla Promise ships a PHPStan extension that improves type inference for
`Promise::resolved()`.

### Why the extension is needed

`void` and `null` are distinct types in static analysis:

| Type                     | Meaning                                             |
| ------------------------ | --------------------------------------------------- |
| `PromiseInterface<null>` | Resolves with the value `null`, a concrete result   |
| `PromiseInterface<void>` | Resolves with no meaningful value, a side effect    |

Without the extension, PHPStan infers `Promise::resolved()` with no argument
as `PromiseInterface<null>` because the parameter defaults to `null`. This
means any function that correctly declares a `PromiseInterface<void>` return
type will fail analysis:
```php
/** @return PromiseInterface<void> */
function sendEmail(): PromiseInterface
{
    return Promise::resolved();
}
```
```
Function sendEmail() should return PromiseInterface<void>
but returns PromiseInterface<null>.
```

With the extension registered, `Promise::resolved()` with no argument is
correctly inferred as `PromiseInterface<void>` and the error disappears.
`Promise::resolved($value)` is inferred as `PromiseInterface<T>` where `T`
is the type of `$value`:
```php
Promise::resolved();        // PromiseInterface<void>
Promise::resolved(42);      // PromiseInterface<int>
Promise::resolved('hello'); // PromiseInterface<string>
Promise::resolved($user);   // PromiseInterface<User>
```

Without this extension, every side-effect promise in your codebase would
require a manual `@return PromiseInterface<void>` annotation just to satisfy
the analyzer, or worse, the return type would silently degrade to
`PromiseInterface<null>`, losing the distinction entirely.

### Registering the extension

**Option 1 — Manual `phpstan.neon` include:**
```neon
includes:
    - vendor/hiblaphp/promise/extension.neon
```

**Option 2 — Automatic registration via PHPStan Extension Installer:**

If you have [`phpstan/extension-installer`](https://github.com/phpstan/extension-installer)
in your project, the extension is registered automatically with no
`phpstan.neon` changes needed. Just install it:
```bash
composer require --dev phpstan/extension-installer
```

Then allow the plugin in your `composer.json`:
```json
{
    "config": {
        "allow-plugins": {
            "phpstan/extension-installer": true
        }
    }
}
```

From that point on, any Composer package that ships a PHPStan extension
(including `hiblaphp/promise`) is discovered and registered automatically.
No manual includes required.

---

## Development
```bash
git clone https://github.com/hiblaphp/promise.git
cd promise
composer install
```
```bash
./vendor/bin/pest
```
```bash
./vendor/bin/phpstan analyse
```

---

## Credits

- **API Design:** Inspired by the [Promise/A+ specification](https://promisesaplus.com/)
  and the JavaScript `Promise` API, with the addition of a first-class cancelled
  state and cooperative cancellation.
- **Event Loop:** Powered by [hiblaphp/event-loop](https://github.com/hiblaphp/event-loop).
- **Cancellation is not an Error** by Kirk Shoop [pdf](https://www.open-std.org/jtc1/sc22/wg21/docs/papers/2019/p1677r1.pdf)

---

## License

MIT License. See [LICENSE](./LICENSE) for more information.