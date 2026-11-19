<?php

/**
 * Migration: adds schedule runs to plugins.
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
 * When each of a plugin's scheduled jobs was last queued (I-3445): job key =>
 * ISO-8601 time. An empty object means none have run yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plugins', function (Blueprint $table): void {
            $table->jsonb('schedule_runs')->default('{}');
        });

        DB::statement("ALTER TABLE plugins ADD CONSTRAINT plugins_schedule_runs_object CHECK (jsonb_typeof(schedule_runs) = 'object')");
    }

    public function down(): void
    {
        Schema::table('plugins', function (Blueprint $table): void {
            $table->dropColumn('schedule_runs');
        });
    }
};
