<?php

/**
 * The addresses waiting to be purged at the CDN in front of the site
 * (slice 1.7d″): a row for each file of the static-file driver that went,
 * numbered by PostgreSQL as it is written, since an answer the web server
 * gave from a file carries no Cache-Tag. Cache data, never backed up or
 * exported, like page_cache_cdn_purges.
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
        Schema::create('page_cache_cdn_addresses', function (Blueprint $table): void {
            $table->id();
            $table->text('path');

            $table->index(['path', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_cache_cdn_addresses');
    }
};
