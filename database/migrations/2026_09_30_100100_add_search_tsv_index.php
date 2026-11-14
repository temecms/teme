<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Slice 1.5b: the index body search reads. Built concurrently so a large
 * contents table stays writable, which cannot run inside a transaction, so
 * this migration holds nothing else (.ai/rules/migrations.md).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS contents_search_tsv ON contents USING gin (search_tsv)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS contents_search_tsv');
    }
};
