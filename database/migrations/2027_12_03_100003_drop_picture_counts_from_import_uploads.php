<?php

/**
 * Drops the copy's cumulative picture counts (review item 8): the run's report
 * counts the pictures each run brought, by where their files came from, from
 * the import map, so a copy keeps no count of its own.
 *
 * @package Teme
 * @since   next
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
        Schema::table('import_uploads', function (Blueprint $table): void {
            $table->dropColumn(['copied_pictures', 'site_pictures']);
        });
    }

    public function down(): void
    {
        Schema::table('import_uploads', function (Blueprint $table): void {
            $table->unsignedInteger('copied_pictures')->default(0);
            $table->unsignedInteger('site_pictures')->default(0);
        });
    }
};
