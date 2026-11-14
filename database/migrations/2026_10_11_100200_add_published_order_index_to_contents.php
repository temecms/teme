<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Slice 1.6h: site search's candidates, the newest matches of every
 * searchable type (I-458), walked newest first from this index until the cap
 * is reached, so a common word reads about as many rows as the cap however
 * many items hold it; a rare word goes through the full-text index instead.
 * The type-led indexes can't give one order across types. Built concurrently
 * so a large contents table stays writable, which cannot run inside a
 * transaction, so this migration holds nothing else (.ai/rules/migrations.md).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS contents_published_order ON contents (published_at DESC, id DESC) WHERE status = 'published' AND deleted_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS contents_published_order');
    }
};
