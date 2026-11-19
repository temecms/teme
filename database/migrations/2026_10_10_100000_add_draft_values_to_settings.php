<?php

/**
 * Migration: adds draft values to settings.
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
 * Settings › Design's unpublished changes (1.6f, study H): a group's draft
 * sits beside its live overrides, holding only the keys changed, until
 * PublishDesign writes them through SettingsWriter. The draft has the same
 * checks as overrides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->jsonb('draft_values')->nullable();
            $table->foreignId('draft_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('draft_updated_at')->nullable();
        });

        DB::statement("ALTER TABLE settings ADD CONSTRAINT settings_draft_values_object CHECK (draft_values IS NULL OR jsonb_typeof(draft_values) = 'object')");
        DB::statement('ALTER TABLE settings ADD CONSTRAINT settings_draft_values_size CHECK (draft_values IS NULL OR pg_column_size(draft_values) <= 65536)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE settings DROP CONSTRAINT settings_draft_values_size');
        DB::statement('ALTER TABLE settings DROP CONSTRAINT settings_draft_values_object');

        Schema::table('settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('draft_updated_by');
            $table->dropColumn(['draft_values', 'draft_updated_at']);
        });
    }
};
