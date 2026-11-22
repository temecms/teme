<?php

/**
 * Migration: lets an import source be a site read from an export file, never connected live, and links a run to the file it reads.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * One site whichever way it came: a site read from a file has an address and
 * nothing of the companion's until it is connected live, when connecting fills
 * the four together. A run with a file reads the file; without one, the
 * companion (docs/architecture/import.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE import_sources DROP CONSTRAINT import_sources_forgotten_check');

        Schema::table('import_sources', function (Blueprint $table): void {
            $table->text('api_root')->nullable()->change();
            $table->string('plugin_version', 40)->nullable()->change();
            $table->smallInteger('api_version')->nullable()->change();
            $table->timestampTz('probed_at')->nullable()->change();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE import_sources
                ADD CONSTRAINT import_sources_forgotten_check CHECK (api_root IS NULL OR ((credential IS NULL) = (forgotten_at IS NOT NULL))),
                ADD CONSTRAINT import_sources_connected_check CHECK (
                    (api_root IS NULL) = (plugin_version IS NULL)
                    AND (api_root IS NULL) = (api_version IS NULL)
                    AND (api_root IS NULL) = (probed_at IS NULL)
                ),
                ADD CONSTRAINT import_sources_never_connected_check CHECK (api_root IS NOT NULL OR (credential IS NULL AND user_name IS NULL AND forgotten_at IS NULL))
            SQL);

        Schema::table('import_runs', function (Blueprint $table): void {
            $table->foreignUlid('file_id')->nullable()->constrained('import_files')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('file_id');
        });

        DB::statement('ALTER TABLE import_sources DROP CONSTRAINT import_sources_never_connected_check, DROP CONSTRAINT import_sources_connected_check, DROP CONSTRAINT import_sources_forgotten_check');
        DB::statement('DELETE FROM import_sources WHERE api_root IS NULL');

        Schema::table('import_sources', function (Blueprint $table): void {
            $table->text('api_root')->nullable(false)->change();
            $table->string('plugin_version', 40)->nullable(false)->change();
            $table->smallInteger('api_version')->nullable(false)->change();
            $table->timestampTz('probed_at')->nullable(false)->change();
        });

        DB::statement('ALTER TABLE import_sources ADD CONSTRAINT import_sources_forgotten_check CHECK ((credential IS NULL) = (forgotten_at IS NOT NULL))');
    }
};
