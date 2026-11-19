<?php

/**
 * Migration: creates extension tables.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The plugins and themes asked for from the admin or the shell, the sources
 * they come from and the archives people upload (I-211; docs/plans/plugin-installer.md).
 * Written only by the extension Operations; the installer service carries
 * each request out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extension_sources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->text('kind');
            $table->text('label');
            $table->text('url')->nullable();
            $table->text('username')->nullable();
            $table->text('credential')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['kind', 'url']);
        });
        DB::statement("ALTER TABLE extension_sources ADD CONSTRAINT extension_sources_kind CHECK (kind ~ '^[a-z][a-z0-9_]{1,31}$')");

        Schema::create('extension_uploads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->text('file_name');
            $table->bigInteger('size');
            $table->char('sha256', 64);
            $table->text('package');
            $table->text('version');
            $table->binary('bytes');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at');
        });

        Schema::create('extension_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->text('action');
            $table->text('kind');
            $table->text('package')->nullable();
            $table->text('constraint')->nullable();
            $table->text('source_kind')->nullable();
            $table->foreignUlid('source_id')->nullable()->constrained('extension_sources')->nullOnDelete();
            $table->foreignUlid('upload_id')->nullable()->constrained('extension_uploads')->nullOnDelete();
            $table->text('status');
            $table->text('step')->nullable();
            $table->jsonb('steps')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('needs')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('agreed')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('report')->default(DB::raw("'{}'::jsonb"));
            $table->text('error')->nullable();
            $table->text('from_release')->nullable();
            $table->text('to_release')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('agreed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('requested_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->index('requested_at');
        });
        DB::statement("ALTER TABLE extension_requests ADD CONSTRAINT extension_requests_action CHECK (action IN ('install', 'update', 'remove', 'rollback', 'rebuild'))");
        DB::statement("ALTER TABLE extension_requests ADD CONSTRAINT extension_requests_kind CHECK (kind IN ('plugin', 'theme'))");
        DB::statement("ALTER TABLE extension_requests ADD CONSTRAINT extension_requests_status CHECK (status IN ('requested', 'running', 'waiting', 'done', 'failed', 'cancelled'))");
        DB::statement("ALTER TABLE extension_requests ADD CONSTRAINT extension_requests_package CHECK ((action IN ('rollback', 'rebuild')) = (package IS NULL))");
        DB::statement("ALTER TABLE extension_requests ADD CONSTRAINT extension_requests_objects CHECK (jsonb_typeof(steps) = 'array' AND jsonb_typeof(needs) = 'object' AND jsonb_typeof(agreed) = 'object' AND jsonb_typeof(report) = 'object')");
        // One open request at a time, whoever writes the row.
        DB::statement("CREATE UNIQUE INDEX extension_requests_one_open ON extension_requests ((true)) WHERE status IN ('requested', 'running', 'waiting')");
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_requests');
        Schema::dropIfExists('extension_uploads');
        Schema::dropIfExists('extension_sources');
    }
};
