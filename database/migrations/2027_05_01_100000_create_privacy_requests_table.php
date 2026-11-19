<?php

/**
 * Migration: creates privacy requests table.
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
 * Requests to export or erase a person's personal data (I-3375): one row per
 * request with typed columns (rule 2), and what each holder of personal data
 * answered as one JSON list, in the order the holders ran (rule 4). The
 * request is itself personal data, so its address goes after 30 days, its
 * hash after a year, with the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('kind', 16);
            $table->string('email', 254)->nullable();
            $table->char('email_hash', 64);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note');
            $table->boolean('reply')->default(true);
            $table->jsonb('results')->default('[]');
            $table->string('export_path', 255)->nullable();
            $table->text('failure')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE privacy_requests
                ADD CONSTRAINT privacy_requests_kind_check CHECK (kind IN ('export', 'erase')),
                ADD CONSTRAINT privacy_requests_status_check CHECK (status IN ('pending', 'running', 'done', 'failed')),
                ADD CONSTRAINT privacy_requests_email_check CHECK (email IS NULL OR (email = lower(email) AND email <> '')),
                ADD CONSTRAINT privacy_requests_hash_check CHECK (email_hash ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT privacy_requests_note_check CHECK (char_length(note) BETWEEN 1 AND 2000),
                ADD CONSTRAINT privacy_requests_results_check CHECK (jsonb_typeof(results) = 'array'),
                ADD CONSTRAINT privacy_requests_export_check CHECK (export_path IS NULL OR kind = 'export'),
                ADD CONSTRAINT privacy_requests_ended_check CHECK ((status IN ('done', 'failed')) = (completed_at IS NOT NULL)),
                ADD CONSTRAINT privacy_requests_failure_check CHECK (failure IS NULL OR status = 'failed')
            SQL);

        DB::statement('CREATE INDEX privacy_requests_hash ON privacy_requests (email_hash, created_at)');
        DB::statement('CREATE INDEX privacy_requests_ended ON privacy_requests (completed_at) WHERE completed_at IS NOT NULL');
        // One open request of a kind per address, kept by the database: two recorders at once can't both pass a read-then-insert check.
        DB::statement("CREATE UNIQUE INDEX privacy_requests_open ON privacy_requests (kind, email_hash) WHERE status IN ('pending', 'running')");
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_requests');
    }
};
