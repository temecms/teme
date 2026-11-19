<?php

/**
 * Migration: adds roles to new sites.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * A new site's roles (I-449): on a database with no role, the four
     * RoleSeeder lists, each with those of its permissions the migrations
     * before this one have made; later permission migrations grant theirs.
     * Where any role exists it does nothing, so a site's own changes stand.
     */
    public function up(): void
    {
        if (Role::query()->exists()) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // Never made here: each permission comes from its own migration, which a test holds (I-449).
        $made = Permission::query()->pluck('name')->all();

        foreach (RoleSeeder::ROLES as $role => $permissions) {
            Role::findOrCreate($role)->givePermissionTo(array_values(array_intersect($permissions, $made)));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Nothing: RoleSeeder makes the same rows, so this can't tell which ones it made.
    }
};
