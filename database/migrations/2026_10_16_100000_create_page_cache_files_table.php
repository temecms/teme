<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The plain files the static-file driver writes for the web server
 * (slice 1.7d″): a row for each, by its page's entry, with the address it
 * answers and the moment it must be gone, two minutes before its page. A
 * row is written before its file and deleted only after it, by the prune,
 * so the table knows every file and nothing lists the folder. Cache data,
 * never backed up or exported, like page_cache_tags.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_cache_files', function (Blueprint $table): void {
            $table->char('entry', 64)->collation('C')->primary();
            $table->text('path');
            $table->timestampTz('expires_at');

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_cache_files');
    }
};
