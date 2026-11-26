<?php

/**
 * Permission names and who holds them, in words — shared by Settings and the
 * in-admin 403 page so both say the same thing for the same ability.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin;

use App\Domain\Users\Roles;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

final class PermissionLabels
{
    /**
     * Every core permission whose words are not "Manage {group} settings".
     *
     * @var array<string, string>
     */
    private const array NAMES = [
        'content.create' => 'Write posts',
        'content.edit.own' => 'Edit their own posts',
        'content.edit.any' => 'Edit anyone’s posts',
        'content.publish' => 'Publish posts',
        'content.delete.any' => 'Delete anyone’s posts',
        'content.delete.own' => 'Delete their own posts',
        'page.create' => 'Write pages',
        'page.edit.own' => 'Edit their own pages',
        'page.edit.any' => 'Edit anyone’s pages',
        'page.publish' => 'Publish pages',
        'page.delete.own' => 'Delete their own pages',
        'page.delete.any' => 'Delete anyone’s pages',
        'media.upload' => 'Upload media',
        'media.edit.own' => 'Edit their own media',
        'media.edit.any' => 'Edit anyone’s media',
        'media.delete.own' => 'Delete their own media',
        'media.delete.any' => 'Delete anyone’s media',
        'settings.view' => 'View settings',
        'settings.security.manage' => 'Manage sign-in rules',
        'settings.addresses.manage' => 'Manage addresses',
        'settings.ai.manage' => 'Manage AI settings',
        'terms.manage' => 'Manage categories and tags',
        'users.manage' => 'Manage people',
        'navigation.manage' => 'Manage menus',
        'regions.manage' => 'Manage regions',
        'comments.moderate' => 'Moderate comments',
        'redirects.manage' => 'Manage redirects',
        'imports.run' => 'Run imports',
        'jobs.manage' => 'Manage background jobs',
        'page-cache.clear' => 'Clear the page cache',
        'site-code.manage' => 'Manage site code',
        'ai.use' => 'Use AI',
        'ai-activity.view' => 'View AI activity',
        'backups.manage' => 'Manage backups',
        'types.manage' => 'Manage content types',
        'updates.manage' => 'Manage updates',
        'plugins.manage' => 'Manage plugins',
        'extensions.install' => 'Install themes and plugins',
        'privacy.manage' => 'Manage privacy requests',
        'api.keys.manage' => 'Manage API keys',
    ];

    /**
     * A permission in plain words, such as "Manage AI settings".
     */
    public static function of(string $ability): string
    {
        if (isset(self::NAMES[$ability])) {
            return __(self::NAMES[$ability]);
        }

        if (preg_match('/^settings\.([a-z-]+)\.manage$/', $ability, $match) === 1) {
            return __('Manage :group settings', ['group' => str_replace('-', ' ', $match[1])]);
        }

        // A permission a plugin adds names itself; the dotted name is shown as written.
        return $ability;
    }

    /**
     * The roles that hold a permission, in words and in the core roles'
     * order, such as "administrators" or "administrators and editors".
     * One query loads every core role with its permissions.
     */
    public static function holders(string $ability): string
    {
        $roles = Role::query()
            ->whereIn('name', array_keys(Roles::LABELS))
            ->with('permissions:id,name')
            ->get()
            ->keyBy('name');

        $names = [];

        foreach (Roles::LABELS as $role => $label) {
            $found = $roles->get($role);

            if ($found !== null && $found->hasPermissionTo($ability)) {
                $names[] = mb_strtolower(__(Str::plural($label)));
            }
        }

        return $names === [] ? __('no role') : Arr::join($names, ', ', ' '.__('and').' ');
    }
}
