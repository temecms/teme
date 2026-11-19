<?php

/**
 * Migration: creates ai activity table.
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
 * The AI activity record (I-318): one row a request, its figures only, never
 * its prompt or its answer. Written by RecordAiActivity when the
 * request ends, settled by SettleAiActivity, pruned at 12 months by
 * PruneAiActivity; nothing else writes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_activity', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('task', 64);
            $table->string('variant', 64)->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('model', 200)->nullable();
            $table->string('prompt_key', 64);
            $table->smallInteger('prompt_version');
            $table->char('prompt_hash', 64);
            $table->string('subject_type', 16);
            // Content IDs are lower case, media IDs upper case: kept as given.
            $table->string('subject_id', 26);
            $table->string('outcome', 16);
            $table->string('error_type', 32)->nullable();
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->integer('cache_read_tokens')->default(0);
            $table->integer('cache_write_tokens')->default(0);
            $table->bigInteger('cost_microunits')->nullable();
            $table->integer('latency_ms')->default(0);
            $table->foreignUlid('revision_id')->nullable()->constrained('content_revisions')->nullOnDelete();
            $table->timestampTz('created_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ai_activity
                ADD CONSTRAINT ai_activity_subject_type_check CHECK (subject_type IN ('content', 'media')),
                ADD CONSTRAINT ai_activity_outcome_check CHECK (outcome IN ('suggested', 'applied', 'discarded', 'failed', 'refused', 'stale')),
                ADD CONSTRAINT ai_activity_error_type_check CHECK (
                    (outcome = 'failed' AND error_type IN ('unavailable', 'rate-limited', 'invalid-output', 'timeout'))
                    OR (outcome = 'refused' AND error_type = 'refused')
                    OR (outcome NOT IN ('failed', 'refused') AND error_type IS NULL)
                ),
                ADD CONSTRAINT ai_activity_revision_check CHECK (revision_id IS NULL OR outcome = 'applied'),
                ADD CONSTRAINT ai_activity_figures_check CHECK (
                    input_tokens >= 0 AND output_tokens >= 0 AND cache_read_tokens >= 0 AND cache_write_tokens >= 0
                    AND latency_ms >= 0 AND (cost_microunits IS NULL OR cost_microunits >= 0)
                )
            SQL);

        // The record, newest first, a keyset page at a time; and the prune's cut-off.
        DB::statement('CREATE INDEX ai_activity_created_index ON ai_activity (created_at DESC, id DESC)');
        // One person's month, and one task's (I-1307's filters).
        DB::statement('CREATE INDEX ai_activity_user_index ON ai_activity (user_id, created_at DESC)');
        DB::statement('CREATE INDEX ai_activity_task_index ON ai_activity (task, created_at DESC)');
        // The row a revision came from; and deleting a revision sets it null without a scan.
        DB::statement('CREATE INDEX ai_activity_revision_index ON ai_activity (revision_id) WHERE revision_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_activity');
    }
};
