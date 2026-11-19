<?php

/**
 * Migration: drops single language slug index.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Drops the one-language slug index (I-3360): a French "about" beside an
 * English one needs it gone, and contents_type_slug_language_live_unique,
 * built by the previous migration, allows everything it did. Dropped
 * concurrently, which cannot run inside a transaction.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->requireValid('contents_type_slug_language_live_unique', 'CREATE UNIQUE INDEX CONCURRENTLY contents_type_slug_language_live_unique ON contents (type, slug, language) NULLS NOT DISTINCT WHERE deleted_at IS NULL');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS contents_type_slug_live_unique');
    }

    public function down(): void
    {
        // Fails, saying why, while two languages share a slug: the old index allows one live slug a type.
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS contents_type_slug_live_unique ON contents (type, slug) WHERE deleted_at IS NULL');
    }

    /**
     * Makes sure the index that replaces the one dropped is valid first: a
     * cancelled build of the earlier migration leaves it invalid, and that
     * migration, already recorded, won't run again. So it is dropped and
     * built here, and if it still isn't valid this stops, the old one kept.
     */
    private function requireValid(string $index, string $create): void
    {
        if ($this->isValid($index)) {
            return;
        }

        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");

        try {
            DB::statement($create);
        } catch (QueryException) {
            // The build failed; the check below says so.
        }

        if (! $this->isValid($index)) {
            throw new RuntimeException("{$index} could not be built, so the old index stays. Look for what stops it, such as two rows with the same slug in one language, then run php artisan migrate again.");
        }
    }

    /**
     * Whether the index exists and PostgreSQL may use it.
     */
    private function isValid(string $index): bool
    {
        $row = DB::selectOne('SELECT i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relname = ? AND n.nspname = current_schema()', [$index]);

        return $row !== null && (bool) $row->indisvalid;
    }
};
