<?php

declare(strict_types=1);

namespace Hibla\Promise\Exceptions;

class TimeoutException extends \RuntimeException
{
    public function __construct(string|float $timeout)
    {
        $message = \is_string($timeout)
            ? $timeout
            : "Operation timed out after {$timeout} seconds";

        parent::__construct($message);
    }
}
