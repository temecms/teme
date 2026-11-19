<?php

/**
 * Migration: creates bulk runs table.
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
 * Select all matching's runs (I-143): one row for each, saying which list,
 * which action, where it stopped and what it skipped, so a failed run carries
 * on from there. It goes with its tracked job, and with its person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tracked_job_id')->unique()->constrained('tracked_jobs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('content_type', 64);
            $table->string('action', 16);
            $table->jsonb('filters')->default('{}');
            $table->text('after')->nullable();
            $table->integer('done')->default(0);
            $table->jsonb('skipped')->default('{}');
            $table->string('refusal', 300)->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE bulk_runs
                ADD CONSTRAINT bulk_runs_action_check CHECK (action IN ('trash', 'restore', 'delete')),
                ADD CONSTRAINT bulk_runs_filters_check CHECK (jsonb_typeof(filters) = 'object'),
                ADD CONSTRAINT bulk_runs_skipped_check CHECK (jsonb_typeof(skipped) = 'object'),
                ADD CONSTRAINT bulk_runs_done_check CHECK (done >= 0)
            SQL);

        // A person's account going takes their runs without a scan.
        DB::statement('CREATE INDEX bulk_runs_user_index ON bulk_runs (user_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_runs');
    }
};
