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
     * Core settings permissions whose words are not "Manage {group} settings".
     *
     * @var array<string, string>
     */
    private const array NAMES = [
        'settings.security.manage' => 'Manage sign-in rules',
        'settings.addresses.manage' => 'Manage addresses',
        'settings.ai.manage' => 'Manage AI settings',
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

        return __(Str::title(str_replace(['.', '_', '-'], ' ', $ability)));
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
