<?php

/**
 * Migration: adds sitemap index to contents.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The sitemaps' pages, each a range of one type's published items in ID
 * order (I-296): read from here by key, never by OFFSET, and every page's
 * start counted from the index alone, published_at included so "live by
 * now" is read from it too. Built concurrently so a large contents table
 * stays writable, which cannot run inside a transaction, so this migration
 * holds nothing else.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS contents_sitemap ON contents (type, id) INCLUDE (published_at) WHERE status = 'published' AND deleted_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS contents_sitemap');
    }
};
