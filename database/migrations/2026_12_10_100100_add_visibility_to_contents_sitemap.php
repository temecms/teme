<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The sitemaps' index, public items only (I-1700): every page's start is
 * still counted from the index alone, with no read of the table per item.
 * Built under a new name before the old one goes, so the sitemaps always
 * have one; concurrently, so a large contents table stays writable, which
 * cannot run inside a transaction, so this migration holds nothing else.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS contents_sitemap_next ON contents (type, id) INCLUDE (published_at) WHERE status = 'published' AND deleted_at IS NULL AND visibility = 'public'");
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS contents_sitemap');
        DB::statement('ALTER INDEX contents_sitemap_next RENAME TO contents_sitemap');
    }

    public function down(): void
    {
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS contents_sitemap_next ON contents (type, id) INCLUDE (published_at) WHERE status = 'published' AND deleted_at IS NULL");
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS contents_sitemap');
        DB::statement('ALTER INDEX contents_sitemap_next RENAME TO contents_sitemap');
    }
};
