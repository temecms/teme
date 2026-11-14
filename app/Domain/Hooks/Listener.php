<?php

declare(strict_types=1);

namespace App\Domain\Hooks;

use Closure;
use Teme\Contracts\Hooks\Order;

/**
 * One subscription the dispatcher calls: whose it is, its handle's ID, the
 * listener, where it asked to run, and when it was made, counted across
 * the process, which breaks ties (I-3353).
 */
final readonly class Listener
{
    public function __construct(
        public string $id,
        public string $owner,
        public Closure $callback,
        public Order $order,
        public int $index,
    ) {}
}
