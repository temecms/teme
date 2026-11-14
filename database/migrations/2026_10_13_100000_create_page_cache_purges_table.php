<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The page cache's memory of recent purges, for its race guard (I-264,
 * docs/studies/page-cache.md §3.7): a row for each tag each purge removed,
 * stamped to the microsecond, so a page whose drawing began before a purge
 * of one of its tags is never stored after it. Rows older than the longest
 * a page may take to draw are pruned every minute, so the table grows with
 * recent activity, never with the number of pages or tags. Cache data:
 * never exported, backed up or restored (I-357).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_cache_purges', function (Blueprint $table): void {
            $table->string('tag', 255)->collation('C');
            $table->timestampTz('purged_at', 6);

            $table->primary(['tag', 'purged_at']);
            $table->index('purged_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_cache_purges');
    }
};
