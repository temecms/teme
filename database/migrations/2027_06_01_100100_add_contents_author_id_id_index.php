<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * An account's items by ID, from the index alone (I-3366): whether bylines
 * touch any of them (Credits::bylinesTouch(), read on each author page)
 * merges this with the bylines' own index instead of reading every item's
 * row. Built concurrently, which cannot run inside a transaction. A build
 * cancelled half way leaves its index invalid, which IF NOT EXISTS would
 * then skip: such an index is dropped and built again.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $invalid = DB::selectOne("SELECT 1 FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relname = 'contents_author_id_id' AND n.nspname = current_schema() AND NOT i.indisvalid");

        if ($invalid !== null) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS contents_author_id_id');
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS contents_author_id_id ON contents (author_id, id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS contents_author_id_id');
    }
};
