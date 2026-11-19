<?php

/**
 * Migration: creates import runs table.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Each import of a source: its kind and state, the phase it's in and that
 * attempt's token, the options and choices it ran with, its counts, its
 * high-water mark, and why it stopped. One runs per source at a time
 * (I-332).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('source_id')->constrained('import_sources')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('state', 16);
            $table->string('phase', 64)->nullable();
            $table->char('phase_token', 26)->nullable();
            $table->jsonb('options')->default('{}');
            $table->jsonb('choices')->default('{}');
            $table->integer('created_count')->default(0);
            $table->integer('updated_count')->default(0);
            $table->integer('skipped_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->integer('warning_count')->default(0);
            $table->timestampTz('high_water_at');
            $table->string('stop_reason', 64)->nullable();
            $table->text('stop_message')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE import_runs
                ADD CONSTRAINT import_runs_kind_check CHECK (kind IN ('full', 'catch-up')),
                ADD CONSTRAINT import_runs_state_check CHECK (state IN ('running', 'stopped', 'finished')),
                ADD CONSTRAINT import_runs_token_check CHECK ((state = 'running') = (phase_token IS NOT NULL)),
                ADD CONSTRAINT import_runs_ended_check CHECK ((state = 'running') = (ended_at IS NULL)),
                ADD CONSTRAINT import_runs_stopped_check CHECK ((state = 'stopped') = (stop_reason IS NOT NULL)),
                ADD CONSTRAINT import_runs_options_check CHECK (jsonb_typeof(options) = 'object'),
                ADD CONSTRAINT import_runs_choices_check CHECK (jsonb_typeof(choices) = 'object'),
                ADD CONSTRAINT import_runs_counts_check CHECK (
                    created_count >= 0 AND updated_count >= 0 AND skipped_count >= 0 AND failed_count >= 0 AND warning_count >= 0
                )
            SQL);

        // One run at a time per source.
        DB::statement("CREATE UNIQUE INDEX import_runs_one_running_index ON import_runs (source_id) WHERE state = 'running'");
        // A source's runs, the latest first: the screen's, and a catch-up's last finished run.
        DB::statement('CREATE INDEX import_runs_source_started_index ON import_runs (source_id, started_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('import_runs');
    }
};
