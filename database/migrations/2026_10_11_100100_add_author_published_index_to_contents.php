<?php

/**
 * Migration: adds author published index to contents.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Slice 1.6h: an author's public archive, a type's published, live items by
 * one person, newest first, read a page at a time straight from this index;
 * the list screens' author index is in update order, so the archive sorted
 * all of an author's posts for every page (4,995 rows for page 400 at a
 * million). Built concurrently so a large contents table stays writable,
 * which cannot run inside a transaction, so this migration holds nothing else
 * (.ai/rules/migrations.md).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS contents_author_published ON contents (type, author_id, published_at DESC, id DESC) WHERE status = 'published' AND deleted_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS contents_author_published');
    }
};
