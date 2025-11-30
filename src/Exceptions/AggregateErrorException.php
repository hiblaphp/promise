<?php

namespace Hibla\Promise\Exceptions;

use Exception;

class AggregateErrorException extends Exception
{
    /** @var array<int|string, mixed> */
    private array $errors;

    /**
     * @param array<int|string, mixed> $errors
     */
    public function __construct(array $errors, string $message = 'All promises were rejected')
    {
        parent::__construct($message);
        $this->errors = $errors;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
