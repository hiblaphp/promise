<?php

declare(strict_types=1);

namespace Hibla\Promise;

enum PromiseState: string
{
    case PENDING = 'pending';
    case FULFILLED = 'fulfilled';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';
}
