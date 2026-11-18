<?php

/**
 * Migration: adds unique key to tracked jobs.
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
 * A plugin job's unique key (docs/proposals/plugin-sockets-1.md): while a
 * run with the key waits, queuing the job again returns that run. The
 * partial index is the backstop behind the advisory lock PluginJobs takes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracked_jobs', function (Blueprint $table): void {
            $table->string('unique_key', 64)->nullable();
        });

        DB::statement("CREATE UNIQUE INDEX tracked_jobs_waiting_unique ON tracked_jobs (owner, run_id, unique_key) WHERE state = 'waiting' AND unique_key IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS tracked_jobs_waiting_unique');

        Schema::table('tracked_jobs', function (Blueprint $table): void {
            $table->dropColumn('unique_key');
        });
    }
};
