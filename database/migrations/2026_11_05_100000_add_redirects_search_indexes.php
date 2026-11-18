<?php

/**
 * Migration: adds redirects search indexes.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Searching Tools › Redirects (I-297): a word anywhere in an address as
 * shown, a typed target or a note, found through trigram indexes rather
 * than by reading every redirect. Built in the migration's transaction:
 * the table first ships in this release, so no site has rows in it yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE INDEX redirects_source_trgm ON redirects USING gin (('/' || source) gin_trgm_ops)");
        // Whole, not limited to the kinds the search reads: a plan made before the kinds are known can use only a whole index.
        DB::statement('CREATE INDEX redirects_target_trgm ON redirects USING gin (target gin_trgm_ops)');
        DB::statement('CREATE INDEX redirects_note_trgm ON redirects USING gin (note gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS redirects_note_trgm');
        DB::statement('DROP INDEX IF EXISTS redirects_target_trgm');
        DB::statement('DROP INDEX IF EXISTS redirects_source_trgm');
    }
};
