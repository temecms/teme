<?php

/**
 * Migration: creates settings tables.
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

return new class extends Migration
{
    public function up(): void
    {
        // One row per settings group — rule 2's shape. Only overrides are
        // stored; defaults resolve at read time (docs/architecture/settings.md).
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('group_key', 191)->primary();
            $table->jsonb('overrides')->default('{}');
            $table->text('secrets')->nullable();
            $table->unsignedBigInteger('version')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('updated_at')->nullable();
        });

        DB::statement("ALTER TABLE settings ADD CONSTRAINT settings_overrides_object CHECK (jsonb_typeof(overrides) = 'object')");
        DB::statement('ALTER TABLE settings ADD CONSTRAINT settings_overrides_size CHECK (pg_column_size(overrides) <= 65536)');

        Schema::create('settings_changes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('group_key', 191)->index();
            $table->jsonb('changed_keys');
            $table->jsonb('before');
            $table->jsonb('after');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('origin', 16)->default('human');
            $table->timestampTz('created_at');
        });

        DB::statement("ALTER TABLE settings_changes ADD CONSTRAINT settings_changes_origin_check CHECK (origin IN ('human', 'ai', 'import', 'system'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('settings_changes');
        Schema::dropIfExists('settings');
    }
};
