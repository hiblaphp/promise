<?php

namespace Hibla\Promise\Handlers;

use Exception;
use Hibla\EventLoop\EventLoop;
use Hibla\Promise\Interfaces\PromiseInterface;
use Throwable;

final class AwaitHandler
{
    /**
     * Block execution until the promise resolves and return the value.
     *
     * @template TValue
     *
     * @param  PromiseInterface<TValue>  $promise
     * @param  bool  $resetEventLoop  Whether to reset the event loop after completion (default: true)
     * @return TValue
     *
     * @throws Throwable
     */
    public function await(PromiseInterface $promise, bool $resetEventLoop = true): mixed
    {
        try {
            if ($promise->isResolved()) {
                return $promise->getValue();
            }

            if ($promise->isRejected()) {
                $reason = $promise->getReason();

                throw $reason instanceof Throwable ? $reason : new Exception($this->safeStringCast($reason));
            }

            $result = null;
            $error = null;
            $completed = false;

            $promise
                ->then(function ($value) use (&$result, &$completed) {
                    $result = $value;
                    $completed = true;

                    return $value;
                })
                ->catch(function ($reason) use (&$error, &$completed) {
                    $error = $reason;
                    $completed = true;

                    return $reason;
                })
            ;

            while (! $completed) {
                EventLoop::getInstance()->run();
            }

            if ($error !== null) {
                throw $error instanceof Throwable ? $error : new Exception($this->safeStringCast($error));
            }

            return $result;
        } finally {
            if ($resetEventLoop) {
                EventLoop::reset();
            }
        }
    }

    /**
     * Safely convert mixed value to string for error messages
     */
    private function safeStringCast(mixed $value): string
    {
        return match (true) {
            \is_string($value) => $value,
            \is_null($value) => 'null',
            \is_scalar($value) => (string) $value,
            \is_object($value) && method_exists($value, '__toString') => (string) $value,
            \is_array($value) => 'Array: ' . json_encode($value),
            \is_object($value) => 'Object: ' . get_class($value),
            default => 'Unknown error type: ' . gettype($value)
        };
    }
}
