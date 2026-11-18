<?php

/**
 * Migration: creates backup keys table.
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
 * restic's key IDs for each place (I-359): the machine key and the recovery
 * keys that open its repository, who added and removed each, and when. Never
 * the key itself: Teme keeps the machine key as a secret setting and no copy
 * of a recovery key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_keys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('destination_id')->constrained('backup_destinations')->restrictOnDelete();
            $table->char('restic_key_id', 64);
            $table->string('role', 16);
            $table->string('label', 100);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('added_at');
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('removed_at')->nullable();
            $table->unique(['destination_id', 'restic_key_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE backup_keys
                ADD CONSTRAINT backup_keys_role_check CHECK (role IN ('machine', 'recovery')),
                ADD CONSTRAINT backup_keys_restic_key_id_check CHECK (restic_key_id ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT backup_keys_label_check CHECK (btrim(label) <> ''),
                ADD CONSTRAINT backup_keys_removed_check CHECK (
                    (removed_by IS NULL OR removed_at IS NOT NULL) AND (removed_at IS NULL OR removed_at >= added_at)
                )
            SQL);

        // One machine key in use per place (I-359).
        DB::statement("CREATE UNIQUE INDEX backup_keys_one_machine ON backup_keys (destination_id) WHERE role = 'machine' AND removed_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_keys');
    }
};
