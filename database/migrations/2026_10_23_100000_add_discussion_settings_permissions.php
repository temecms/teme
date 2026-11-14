<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * I-449, I-685: 1.8d's two settings permissions reach existing sites here,
 * never by re-running RoleSeeder, which resets every role it names. It
 * creates each permission and grants it to the roles RoleSeeder gives it,
 * where each exists. It only adds: a grant an administrator made is kept, no
 * role is created, and a second run changes nothing.
 */
return new class extends Migration
{
    /** Each permission this part adds, with the roles RoleSeeder gives it. */
    private const array GRANTS = [
        'settings.discussion.manage' => ['administrator'],
        'settings.modules.manage' => ['administrator'],
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
        // Nothing, as in 1.8a's comments.moderate: RoleSeeder makes the same
        // rows on a fresh install, so this can't tell which ones it made.
    }
};
