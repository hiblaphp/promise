<?php

declare(strict_types=1);

namespace Hibla\Promise\Exceptions;

class TimeoutException extends \Exception
{
    public function __construct(float $timeout)
    {
        parent::__construct("Operation timed out after {$timeout} seconds");
    }
}
