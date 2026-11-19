<?php

/**
 * Migration: drops single language term slug key.
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
 * Drops the one-language term slug key (I-3360): a French "actualites" beside
 * an English one needs it gone, and terms_taxonomy_slug_language_unique
 * allows everything it did.
 */
return new class extends Migration
{
    // A concurrent build, to repair the index, cannot run inside a transaction; the drop opens its own.
    public $withinTransaction = false;

    public function up(): void
    {
        $this->requireValid('terms_taxonomy_slug_language_unique', 'CREATE UNIQUE INDEX CONCURRENTLY terms_taxonomy_slug_language_unique ON terms (taxonomy, slug, language) NULLS NOT DISTINCT');

        // In a transaction of its own: a busy table makes it give up, not queue every read behind it.
        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement('ALTER TABLE terms DROP CONSTRAINT IF EXISTS terms_taxonomy_slug_unique');
        });
    }

    public function down(): void
    {
        // Fails, saying why, while two languages share a slug: the old key allows one slug a taxonomy.
        DB::statement('ALTER TABLE terms ADD CONSTRAINT terms_taxonomy_slug_unique UNIQUE (taxonomy, slug)');
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
