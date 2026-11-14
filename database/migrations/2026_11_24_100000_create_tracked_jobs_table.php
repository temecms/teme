<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Tracked jobs (I-104): one row for each piece of background work people
 * follow, how far it's got and how it ended. The work itself runs on
 * Laravel's queue. Only the job Operations write it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracked_jobs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('kind', 64);
            $table->string('owner', 64)->default('core');
            $table->string('label', 200);
            $table->string('starter', 16);
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('run_type', 32)->nullable();
            $table->string('run_id', 64)->nullable();
            $table->string('state', 16);
            $table->integer('total')->nullable();
            $table->integer('done')->default(0);
            $table->string('message', 500)->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->timestampTz('ended_at')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE tracked_jobs
                ADD CONSTRAINT tracked_jobs_state_check CHECK (state IN ('waiting', 'running', 'finished', 'failed', 'cancelled')),
                ADD CONSTRAINT tracked_jobs_ended_check CHECK ((state IN ('finished', 'failed', 'cancelled')) = (ended_at IS NOT NULL)),
                ADD CONSTRAINT tracked_jobs_starter_check CHECK (starter IN ('person', 'operator') AND (starter = 'person' OR started_by IS NULL)),
                ADD CONSTRAINT tracked_jobs_run_check CHECK ((run_type IS NULL) = (run_id IS NULL)),
                ADD CONSTRAINT tracked_jobs_progress_check CHECK (done >= 0 AND (total IS NULL OR (total >= 0 AND done <= total)))
            SQL);

        // Tools › Jobs, newest first, a keyset page at a time (I-140).
        DB::statement('CREATE INDEX tracked_jobs_created_index ON tracked_jobs (created_at DESC, id DESC)');
        // Retry all failed, and the failed count beside it.
        DB::statement("CREATE INDEX tracked_jobs_failed_index ON tracked_jobs (created_at DESC, id DESC) WHERE state = 'failed'");
        // A run's jobs, for the screen that shows the run.
        DB::statement('CREATE INDEX tracked_jobs_run_index ON tracked_jobs (run_type, run_id, created_at DESC) WHERE run_type IS NOT NULL');
        // A person's jobs, and their removal setting started_by null without a scan.
        DB::statement('CREATE INDEX tracked_jobs_started_by_index ON tracked_jobs (started_by, created_at DESC) WHERE started_by IS NOT NULL');
        // The daily prune of jobs that ended 90 days ago.
        DB::statement('CREATE INDEX tracked_jobs_ended_index ON tracked_jobs (ended_at) WHERE ended_at IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_jobs');
    }
};
