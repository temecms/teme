<?php

/**
 * Migration: brings permissions to existing sites.
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
 * I-449: a permission reaches an existing site through a migration, never by
 * re-running RoleSeeder, which resets every role it names. This creates every
 * permission 0.1.0 has, so each one RoleSeeder lists is made by a migration
 * from here on, and grants the two that 1.6a and 1.6b added only to
 * RoleSeeder to the role it gives them, where that role exists. It only adds:
 * a grant an administrator made is kept, and no role is created.
 */
return new class extends Migration
{
    /** Every permission, as RoleSeeder listed them at 1.6b. */
    private const array PERMISSIONS = [
        'content.create', 'content.edit.own', 'content.edit.any', 'content.publish', 'content.delete.any', 'content.delete.own',
        'media.upload', 'media.edit.own', 'media.edit.any', 'media.delete.own', 'media.delete.any',
        'settings.general.manage', 'settings.security.manage', 'settings.content.manage', 'settings.media.manage',
        'settings.addresses.manage', 'settings.design.manage',
        'terms.manage', 'users.manage',
    ];

    /** The permissions 1.6a and 1.6b added, each with the roles RoleSeeder gives it. */
    private const array GRANTS = [
        'settings.addresses.manage' => ['administrator'],
        'settings.design.manage' => ['administrator'],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission);
        }

        foreach (self::GRANTS as $permission => $roles) {
            foreach ($roles as $role) {
                Role::query()->where('name', $role)->first()?->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Nothing. RoleSeeder makes the same rows on a fresh install, so this
        // can't tell which ones it made, and taking a grant away would hide
        // Settings › Addresses and Design from administrators who use them.
    }
};
