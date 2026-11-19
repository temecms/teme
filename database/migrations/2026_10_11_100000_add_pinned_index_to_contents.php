<?php

/**
 * Migration: adds pinned index to contents.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Slice 1.6h: the latest posts' pinned items (I-156), newest first, from an
 * index of only the published, live items whose built-in sticky field
 * (BuiltInFields::STICKY, in `fields`) is true. Without it, a site that pins
 * nothing walked every published post to find none (the front page, 430 ms
 * at a million). An index on the JSONB value, never a column (rule 2). Built
 * concurrently so a large contents table stays writable, which cannot run
 * inside a transaction, so this migration holds nothing else
 * (.ai/rules/migrations.md).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS contents_pinned_published ON contents (type, published_at DESC, id DESC) WHERE status = 'published' AND deleted_at IS NULL AND (fields ->> '01K5Z0F1ED00000000000000P8') = 'true'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS contents_pinned_published');
    }
};
