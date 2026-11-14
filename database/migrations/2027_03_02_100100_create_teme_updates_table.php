<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Each update of Teme asked for from the admin or the shell (I-3326), and how the updater carried it out.
 * Written only by RequestTemeUpdate, CancelTemeUpdate and the updater's three Operations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teme_updates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->text('kind');
            $table->text('from_version');
            $table->text('to_version');
            $table->text('status');
            $table->text('step')->nullable();
            $table->jsonb('steps')->default(DB::raw("'[]'::jsonb"));
            $table->text('error')->nullable();
            $table->text('backup_run_id')->nullable();
            $table->text('deploy_run_id')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('requested_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->index('requested_at');
        });

        $release = "'^(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)(-(alpha|beta|rc)\\.(0|[1-9][0-9]*))?$'";
        DB::statement("ALTER TABLE teme_updates ADD CONSTRAINT teme_updates_versions CHECK (from_version ~ {$release} AND to_version ~ {$release})");
        DB::statement("ALTER TABLE teme_updates ADD CONSTRAINT teme_updates_kind CHECK (kind IN ('update', 'rollback'))");
        DB::statement("ALTER TABLE teme_updates ADD CONSTRAINT teme_updates_status CHECK (status IN ('requested', 'running', 'done', 'switched_back', 'failed', 'cancelled'))");
        DB::statement("ALTER TABLE teme_updates ADD CONSTRAINT teme_updates_steps CHECK (jsonb_typeof(steps) = 'array')");
        // One open request at a time, whoever writes the row.
        DB::statement("CREATE UNIQUE INDEX teme_updates_one_open ON teme_updates ((true)) WHERE status IN ('requested', 'running')");
    }

    public function down(): void
    {
        Schema::dropIfExists('teme_updates');
    }
};
