<?php

/**
 * Migration: creates backup runs table.
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
 * Every backup, copy, check, read-back, test restore, prune and restore
 * Teme runs (I-362), one row each, with restic's snapshot and the figures;
 * never a key and never a secret, in its message too. Only the backup
 * Operations write it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table): void {
            // The table refers to itself, so its key comes before its foreign keys.
            $table->ulid('id');
            $table->primary('id');
            $table->string('kind', 16);
            $table->string('state', 16)->default('running');
            $table->string('started_by', 16);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('destination_id')->nullable()->constrained('backup_destinations')->restrictOnDelete();
            $table->foreignUlid('parent_id')->nullable()->constrained('backup_runs')->restrictOnDelete();
            $table->char('snapshot_id', 64)->nullable();
            $table->jsonb('figures')->default('{}');
            $table->string('message', 2000)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE backup_runs
                ADD CONSTRAINT backup_runs_kind_check CHECK (kind IN ('backup', 'copy', 'check', 'read-back', 'test-restore', 'prune', 'restore')),
                ADD CONSTRAINT backup_runs_state_check CHECK (state IN ('running', 'finished', 'failed')),
                ADD CONSTRAINT backup_runs_ended_check CHECK (
                    (state = 'running') = (ended_at IS NULL) AND (ended_at IS NULL OR ended_at >= started_at)
                ),
                ADD CONSTRAINT backup_runs_started_by_check CHECK (started_by IN ('schedule', 'person', 'deploy', 'command')),
                ADD CONSTRAINT backup_runs_actor_check CHECK (started_by = 'person' OR actor_id IS NULL),
                ADD CONSTRAINT backup_runs_snapshot_check CHECK (snapshot_id IS NULL OR snapshot_id ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT backup_runs_figures_check CHECK (jsonb_typeof(figures) = 'object'),
                ADD CONSTRAINT backup_runs_parent_check CHECK (parent_id IS NULL OR parent_id <> id)
            SQL);

        // The newest run of a kind, for the Status check and the screen, through an index (I-362).
        DB::statement('CREATE INDEX backup_runs_kind_index ON backup_runs (kind, started_at DESC)');
        // Each place's newest backup, copy and check.
        DB::statement('CREATE INDEX backup_runs_destination_index ON backup_runs (destination_id, kind, started_at DESC) WHERE destination_id IS NOT NULL');
        // A run's own copies and checks.
        DB::statement('CREATE INDEX backup_runs_parent_index ON backup_runs (parent_id) WHERE parent_id IS NOT NULL');
        // What is running now.
        DB::statement("CREATE INDEX backup_runs_running_index ON backup_runs (started_at) WHERE state = 'running'");
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
