<?php

/**
 * Locks one source object until the transaction ends, so the Operations
 * that write its map row take turns, and two jobs that reach one object
 * import it once (I-332).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Concerns;

use App\Domain\Import\Phases\SourceRef;
use Illuminate\Support\Facades\DB;

trait LocksObjects
{
    private function lockObject(string $sourceId, SourceRef $ref): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["import:{$sourceId}:{$ref->key()}"]);
    }
}
