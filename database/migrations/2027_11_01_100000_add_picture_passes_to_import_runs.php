<?php

/**
 * Migration: lets an import run be a picture pass, which brings an import's media files after its articles, and links it to the run it follows.
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
 * A picture pass is a run of its own kind, `media`, so the one-running-run
 * index still keeps a site to one run at a time; it names the run whose
 * articles it brings the pictures for (docs/architecture/import.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE import_runs DROP CONSTRAINT import_runs_kind_check');
        DB::statement("ALTER TABLE import_runs ADD CONSTRAINT import_runs_kind_check CHECK (kind IN ('full', 'catch-up', 'media'))");

        Schema::table('import_runs', function (Blueprint $table): void {
            $table->foreignUlid('follows_run_id')->nullable()->constrained('import_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('follows_run_id');
        });

        DB::statement("DELETE FROM import_runs WHERE kind = 'media'");
        DB::statement('ALTER TABLE import_runs DROP CONSTRAINT import_runs_kind_check');
        DB::statement("ALTER TABLE import_runs ADD CONSTRAINT import_runs_kind_check CHECK (kind IN ('full', 'catch-up'))");
    }
};
