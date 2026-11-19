<?php

/**
 * One change at a time to a type's tree and the addresses that follow it
 * (I-3363): a transaction-scoped advisory lock per type, taken before any
 * row lock, so two moves can't make a loop or leave a branch at an old chain.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Tree;

use Illuminate\Support\Facades\DB;

final class TreeLock
{
    /**
     * Waits for, then holds until the transaction ends, the type's tree.
     */
    public static function take(string $type): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['teme.tree.'.$type]);
    }
}
