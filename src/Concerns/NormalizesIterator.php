<?php

declare(strict_types=1);

namespace Hibla\Promise\Concerns;

trait NormalizesIterator
{
    /**
     * Normalize any iterable into an Iterator without materializing it into memory.
     *
     * Arrays are wrapped in a generator, IteratorAggregate implementations are
     * unwrapped, and plain Iterators are returned as-is.
     *
     * @param  iterable<int|string, mixed>  $items
     * @return \Iterator<int|string, mixed>
     */
    private function getIterator(iterable $items): \Iterator
    {
        if ($items instanceof \Iterator) {
            return $items;
        }

        if ($items instanceof \IteratorAggregate) {
            return $items->getIterator();
        }

        return (fn () => yield from $items)();
    }
}
