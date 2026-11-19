<?php

/**
 * Migration: adds payload to tracked jobs.
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
 * Plugin job payloads (I-3445): JSON-ready data queued with a tracked job,
 * kept on the row so Retry can start again with the same payload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracked_jobs', function (Blueprint $table): void {
            $table->jsonb('payload')->default('{}');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE tracked_jobs
                ADD CONSTRAINT tracked_jobs_payload_object CHECK (jsonb_typeof(payload) = 'object')
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tracked_jobs DROP CONSTRAINT IF EXISTS tracked_jobs_payload_object');
        Schema::table('tracked_jobs', function (Blueprint $table): void {
            $table->dropColumn('payload');
        });
    }
};
