<?php

/**
 * Migration: an uploaded file can be read by an import reader a plugin
 * offers, and an import source records the kind of system it came from.
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the reader a file is read by, and the kind of each source.
     */
    public function up(): void
    {
        Schema::table('import_files', function (Blueprint $table): void {
            $table->text('reader')->nullable();
        });

        Schema::table('import_sources', function (Blueprint $table): void {
            $table->text('kind')->default('wordpress');
        });
    }

    /**
     * Removes both columns.
     */
    public function down(): void
    {
        Schema::table('import_sources', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });

        Schema::table('import_files', function (Blueprint $table): void {
            $table->dropColumn('reader');
        });
    }
};
