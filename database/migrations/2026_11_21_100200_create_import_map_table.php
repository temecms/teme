<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * One row per object of a source: what it became in Teme, its state and
 * the last run's outcome, the hash of the source's item, and what a phase
 * keeps that Teme has no column for. A run's work list is read from here,
 * keyed by the source's own IDs, never by slugs (I-332).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_map', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('source_id')->constrained('import_sources')->cascadeOnDelete();
            $table->string('object_type', 40);
            // A WordPress ID, or a file's path below the uploads folder; compared byte for byte.
            $table->text('object_id')->collation('C');
            $table->string('teme_type', 40)->nullable();
            $table->string('teme_id', 64)->nullable();
            $table->string('status', 16);
            $table->string('outcome', 16);
            $table->char('source_hash', 64)->nullable();
            $table->jsonb('facts')->default('{}');
            $table->foreignUlid('run_id')->nullable()->constrained('import_runs')->nullOnDelete();
            $table->timestampTz('imported_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE import_map
                ADD CONSTRAINT import_map_object_type_check CHECK (object_type ~ '^[a-z][a-z0-9]*(-[a-z0-9]+)*$'),
                ADD CONSTRAINT import_map_object_id_check CHECK (char_length(object_id) BETWEEN 1 AND 500),
                ADD CONSTRAINT import_map_teme_check CHECK ((teme_type IS NULL) = (teme_id IS NULL)),
                ADD CONSTRAINT import_map_status_check CHECK (status IN ('imported', 'needs_update', 'skipped', 'failed')),
                ADD CONSTRAINT import_map_outcome_check CHECK (outcome IN ('created', 'updated', 'skipped', 'failed')),
                ADD CONSTRAINT import_map_imported_check CHECK (status <> 'imported' OR teme_id IS NOT NULL),
                ADD CONSTRAINT import_map_facts_check CHECK (jsonb_typeof(facts) = 'object')
            SQL);

        // The key: one row per object of a source.
        DB::statement('CREATE UNIQUE INDEX import_map_object_index ON import_map (source_id, object_type, object_id)');
        // A run's report: its objects by outcome, and its counts.
        DB::statement('CREATE INDEX import_map_run_index ON import_map (run_id, outcome)');
        // From a Teme item back to what it came from.
        DB::statement('CREATE INDEX import_map_teme_index ON import_map (teme_type, teme_id) WHERE teme_id IS NOT NULL');
        // The failures a new run tries again.
        DB::statement("CREATE INDEX import_map_failed_index ON import_map (source_id) WHERE status = 'failed'");
    }

    public function down(): void
    {
        Schema::dropIfExists('import_map');
    }
};
