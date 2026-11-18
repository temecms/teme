<?php

/**
 * Migration: creates page cache tags table.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The page cache's index of what it holds (I-265, docs/studies/page-cache.md
 * §3.8): one row per stored page and tag, with the page's expiry, so a tag
 * or an expiry finds the pages' files without anything listing the cache
 * folder. `entry` is the SHA-256 of the page's key, which names its file.
 * Cache data: never exported, backed up or restored (I-357), and rebuilt as
 * pages are asked for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_cache_tags', function (Blueprint $table): void {
            $table->char('entry', 64)->collation('C');
            $table->string('tag', 255)->collation('C');
            $table->timestampTz('expires_at');

            $table->primary(['entry', 'tag']);
            $table->index('tag');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_cache_tags');
    }
};
