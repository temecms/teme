<?php

/**
 * Migration: adds regions manage permission.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * I-449: 1.6e's regions.manage reaches existing sites here, never by
 * re-running RoleSeeder, which resets every role it names. It creates the
 * permission and grants it to the roles RoleSeeder gives it, where each
 * exists. It only adds: a grant an administrator made is kept, and no role
 * is created.
 */
return new class extends Migration
{
    /** The roles RoleSeeder gives regions.manage. */
    private const array ROLES = ['editor', 'administrator'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate('regions.manage');

        foreach (self::ROLES as $role) {
            Role::query()->where('name', $role)->first()?->givePermissionTo('regions.manage');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Nothing, as in 1.6c's catch-up and 1.6d's navigation.manage:
        // RoleSeeder makes the same rows on a fresh install, so this can't
        // tell which ones it made, and taking the grant away would hide
        // Content › Regions from editors who use it.
    }
};
