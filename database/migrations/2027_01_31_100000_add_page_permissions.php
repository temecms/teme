<?php

/**
 * Migration: adds page permissions.
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
 * Pages' own six permissions (I-3313) reach existing sites here, never by
 * re-running RoleSeeder, which resets every role it names. It creates each
 * and grants it to the roles RoleSeeder gives it, administrators and
 * editors, where each exists. It only adds: a grant made by hand is kept,
 * no role is created, and a second run changes nothing. Pages stop
 * answering to content.* with the code that ships it, so from here authors
 * and contributors no longer write them.
 */
return new class extends Migration
{
    /** Each permission this migration adds, with the roles RoleSeeder gives it. */
    private const array GRANTS = [
        'page.create' => ['administrator', 'editor'],
        'page.edit.own' => ['administrator', 'editor'],
        'page.edit.any' => ['administrator', 'editor'],
        'page.publish' => ['administrator', 'editor'],
        'page.delete.own' => ['administrator'],
        'page.delete.any' => ['administrator', 'editor'],
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
