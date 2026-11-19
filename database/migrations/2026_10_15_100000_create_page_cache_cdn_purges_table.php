<?php

/**
 * The purges waiting for the CDN in front of the site (slice 1.7d′): a row
 * for each tag a change purged, numbered by PostgreSQL as it is written, so
 * the drain sends them oldest first and knows which came after a batch it
 * sent. Cache data, never backed up or exported, like page_cache_tags.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_cache_cdn_purges', function (Blueprint $table): void {
            $table->id();
            $table->text('tag');

            $table->index(['tag', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_cache_cdn_purges');
    }
};
