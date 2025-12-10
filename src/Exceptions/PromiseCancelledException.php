<?php

declare(strict_types=1);

namespace Hibla\Promise\Exceptions;

class PromiseCancelledException extends \RuntimeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
