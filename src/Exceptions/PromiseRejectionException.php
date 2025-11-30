<?php

declare(strict_types=1);

namespace Hibla\Promise\Exceptions;

use Exception;
use Throwable;

/**
 * Exception thrown when a Promise is rejected with a non-Throwable reason.
 */
class PromiseRejectionException extends Exception
{
    public function __construct(mixed $reason, int $code = 0, ?Throwable $previous = null)
    {
        $message = $this->createMessage($reason);

        parent::__construct($message, $code, $previous);
    }

    private function createMessage(mixed $reason): string
    {
        if ($reason === null) {
            return 'Promise rejected with null';
        }

        if (\is_scalar($reason)) {
            return "{$reason}";
        }

        if (\is_object($reason) && method_exists($reason, '__toString')) {
            return "{$reason}";
        }

        $type = get_debug_type($reason);

        return "Promise rejected with {$type}: ".print_r($reason, true);
    }
}
