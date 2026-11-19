<?php

/**
 * Migration: adds migrations to plugins.
 *
 * @package Teme
 * @since   0.8.0-alpha.1
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Which of a plugin's migrations have run (I-3442): name => {version, ran_at,
 * tables}. An empty object means none yet. The plugin's tables are every
 * tables entry across the record that still exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plugins', function (Blueprint $table): void {
            $table->jsonb('migrations')->default('{}');
        });

        DB::statement("ALTER TABLE plugins ADD CONSTRAINT plugins_migrations_object CHECK (jsonb_typeof(migrations) = 'object')");
    }

    public function down(): void
    {
        Schema::table('plugins', function (Blueprint $table): void {
            $table->dropColumn('migrations');
        });
    }
};
