<?php

declare(strict_types=1);

namespace App\Domain\Languages;

use Illuminate\Support\Facades\DB;

/**
 * The site's languages' lock (I-3360), held to the end of the transaction:
 * shared by every Operation that gives an item or term a language or a
 * group, exclusive for one that removes a language or stops a type being
 * translated, so neither commits while the other is in flight. Always the
 * first lock an Operation takes, before a tree's and any row's.
 */
final class LanguageLock
{
    /**
     * Takes the lock beside every other holder of it shared.
     */
    public static function shared(): void
    {
        DB::select("SELECT pg_advisory_xact_lock_shared(hashtext('teme.languages'))");
    }

    /**
     * Takes the lock alone, waiting for every shared holder to commit.
     */
    public static function exclusive(): void
    {
        DB::select("SELECT pg_advisory_xact_lock(hashtext('teme.languages'))");
    }
}
