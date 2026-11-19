<?php

/**
 * Migration: creates api keys table.
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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * The management API's keys (docs/proposals/management-api.md): one row a
 * key, holding only the secret's hash, acting as one person and deleted
 * with them. api.keys.manage reaches existing sites here too, granted to
 * administrators where that role exists, never by re-running RoleSeeder;
 * it only adds, and a second run changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->text('name');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->jsonb('abilities');
            $table->char('token_hash', 64)->unique();
            $table->string('token_start', 12);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('user_id');
        });

        DB::statement("ALTER TABLE api_keys ADD CONSTRAINT api_keys_abilities_array CHECK (jsonb_typeof(abilities) = 'array')");
        // A live key's name is unique, so the list tells keys apart; a revoked key's name may come back.
        DB::statement('CREATE UNIQUE INDEX api_keys_live_name_unique ON api_keys (lower(name)) WHERE revoked_at IS NULL');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('api.keys.manage');
        Role::query()->where('name', 'administrator')->first()?->givePermissionTo('api.keys.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // The permission stays, as in the earlier permission migrations:
        // RoleSeeder makes the same row on a fresh install.
        Schema::dropIfExists('api_keys');
    }
};
