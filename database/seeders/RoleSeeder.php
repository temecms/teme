<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class RoleSeeder extends Seeder
{
    public const PERMISSIONS = [
        'content.create',
        'content.edit.own',
        'content.edit.any',
        'content.publish',
        'content.delete.any',
        'content.delete.own',
        'page.create',
        'page.edit.own',
        'page.edit.any',
        'page.publish',
        'page.delete.own',
        'page.delete.any',
        'media.upload',
        'media.edit.own',
        'media.edit.any',
        'media.delete.own',
        'media.delete.any',
        'settings.general.manage',
        'settings.security.manage',
        'settings.content.manage',
        'settings.media.manage',
        'settings.addresses.manage',
        'settings.design.manage',
        'settings.discussion.manage',
        'settings.modules.manage',
        'settings.seo.manage',
        'settings.ai.manage',
        'settings.fediverse.manage',
        'terms.manage',
        'users.manage',
        'navigation.manage',
        'regions.manage',
        'comments.moderate',
        'redirects.manage',
        'imports.run',
        'jobs.manage',
        'page-cache.clear',
        'site-code.manage',
        'ai.use',
        'ai-activity.view',
        'backups.manage',
        'types.manage',
        'updates.manage',
        'plugins.manage',
        'privacy.manage',
    ];

    public const ROLES = [
        'administrator' => self::PERMISSIONS,
        'editor' => [
            'content.create',
            'content.edit.own',
            'content.edit.any',
            'content.publish',
            'content.delete.any',
            'page.create',
            'page.edit.own',
            'page.edit.any',
            'page.publish',
            'page.delete.any',
            'terms.manage',
            'navigation.manage',
            'regions.manage',
            'comments.moderate',
            'redirects.manage',
            'media.upload',
            'media.edit.own',
            'media.edit.any',
            'media.delete.own',
            'media.delete.any',
            'ai.use',
        ],
        'author' => [
            'content.create',
            'content.edit.own',
            'content.publish',
            'content.delete.own',
            'media.upload',
            'media.edit.own',
            'media.delete.own',
            'ai.use',
        ],
        'contributor' => [
            'content.create',
            'content.edit.own',
            'content.delete.own',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission);
        }

        foreach (self::ROLES as $role => $permissions) {
            Role::findOrCreate($role)->syncPermissions($permissions);
        }
    }
}
