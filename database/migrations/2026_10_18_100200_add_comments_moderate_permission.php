<?php

/**
 * Migration: adds comments moderate permission.
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
 * I-449: 1.8a's comments.moderate reaches existing sites here, never by
 * re-running RoleSeeder, which resets every role it names. It creates the
 * permission and grants it to the roles RoleSeeder gives it, where each
 * exists. It only adds: a grant an administrator made is kept, and no role
 * is created. Authors look after their own items' comments through
 * publishing (I-483, I-570), with no permission of their own.
 */
return new class extends Migration
{
    /** The roles RoleSeeder gives comments.moderate. */
    private const array ROLES = ['editor', 'administrator'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate('comments.moderate');

        foreach (self::ROLES as $role) {
            Role::query()->where('name', $role)->first()?->givePermissionTo('comments.moderate');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Nothing, as in 1.6e's regions.manage: RoleSeeder makes the same rows
        // on a fresh install, so this can't tell which ones it made.
    }
};
