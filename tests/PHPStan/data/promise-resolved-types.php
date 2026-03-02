<?php

declare(strict_types=1);

use Hibla\Promise\Promise;

use function PHPStan\Testing\assertType;

// no argument → void
assertType('Hibla\Promise\Interfaces\PromiseInterface<void>', Promise::resolved());

// explicit null → null
assertType('Hibla\Promise\Interfaces\PromiseInterface<null>', Promise::resolved(null));

// literal string
assertType("Hibla\Promise\Interfaces\PromiseInterface<'hello'>", Promise::resolved('hello'));

// literal int
assertType('Hibla\Promise\Interfaces\PromiseInterface<42>', Promise::resolved(42));

// literal bool
assertType('Hibla\Promise\Interfaces\PromiseInterface<true>', Promise::resolved(true));
