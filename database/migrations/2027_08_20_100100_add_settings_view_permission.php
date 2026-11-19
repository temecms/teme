<?php

/**
 * Migration: adds settings view permission.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * settings.view, which lets a person see every settings group read-only
 * and change none they may not manage, reaches existing sites here, never
 * by re-running RoleSeeder, which resets every role it names. It creates
 * the permission and grants it to administrators, where that role exists.
 * It only adds: a grant an administrator made is kept, no role is created,
 * and a second run changes nothing.
 */
return new class extends Migration
{
    /** The permission this migration adds, with the roles RoleSeeder gives it. */
    private const array GRANTS = [
        'settings.view' => ['administrator'],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::GRANTS as $permission => $roles) {
            Permission::findOrCreate($permission);

            foreach ($roles as $role) {
                Role::query()->where('name', $role)->first()?->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Nothing, as in the earlier permission migrations: RoleSeeder makes
        // the same rows on a fresh install, so this can't tell which it made.
    }
};
