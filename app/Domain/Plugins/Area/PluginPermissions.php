<?php

/**
 * The permissions a plugin declares (docs/plans/plugin-sockets-2.md, A1):
 * their names, `plugin.{code}.{id}`, what the consent lists, and making and
 * removing their rows. The plugin Operations write through it, inside their
 * transaction, with the query builder only, as TypePermissions does.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Area;

use App\Domain\Plugins\Manifest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

final class PluginPermissions
{
    /** The most permissions a plugin may declare. */
    public const int MOST = 3;

    /** A permission's id. */
    public const string ID = '/^[a-z][a-z0-9-]{1,23}$/D';

    /** The roles a plugin may name; administrators always hold its permissions. */
    public const array ROLES = ['editor', 'author', 'contributor'];

    /** The role that holds every plugin permission. */
    public const string ADMINISTRATOR = 'administrator';

    /**
     * A plugin permission's name.
     */
    public static function name(string $code, string $id): string
    {
        return "plugin.{$code}.{$id}";
    }

    /**
     * The permissions the manifest declares, each its id, label, description
     * and the roles it names, in declared order; what isn't well formed is
     * left out (the validator refuses it first).
     *
     * @return list<array{id: string, label: string, description: string, roles: list<string>}>
     */
    public static function declared(Manifest $manifest): array
    {
        $entries = $manifest->contributes()['permissions'] ?? null;
        $declared = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (! is_array($entry) || ! is_string($entry['id'] ?? null) || ! is_string($entry['label'] ?? null)) {
                continue;
            }

            $roles = is_array($entry['roles'] ?? null) ? array_values(array_filter($entry['roles'], fn (mixed $role): bool => is_string($role) && in_array($role, self::ROLES, true))) : [];
            sort($roles);

            $declared[] = [
                'id' => $entry['id'],
                'label' => $entry['label'],
                'description' => is_string($entry['description'] ?? null) ? $entry['description'] : '',
                'roles' => $roles,
            ];
        }

        return $declared;
    }

    /**
     * Each declared permission as the consent record keeps it: its id, then
     * `id:role` for each role it names, so an added permission or an added
     * role asks again and a role taken away doesn't.
     *
     * @return list<string>
     */
    public static function consentLines(Manifest $manifest): array
    {
        $lines = [];

        foreach (self::declared($manifest) as $permission) {
            $lines[] = $permission['id'];

            foreach ($permission['roles'] as $role) {
                $lines[] = "{$permission['id']}:{$role}";
            }
        }

        return $lines;
    }

    /**
     * What the consent screen says for each declared permission: who it is
     * given to, and what it lets them do.
     *
     * @return list<string>
     */
    public static function consentWords(Manifest $manifest): array
    {
        return array_map(fn (array $permission): string => $permission['roles'] === []
            ? __('Administrators only: :label', ['label' => $permission['label']])
            : __('Lets :roles :label', ['roles' => self::roleList($permission['roles']), 'label' => Str::lcfirst($permission['label'])]), self::declared($manifest));
    }

    /**
     * The roles a permission is given to, as the consent names them: one,
     * two or all three, each a fixed word.
     *
     * @param  list<string>  $roles
     */
    private static function roleList(array $roles): string
    {
        $words = array_map(fn (string $role): string => match ($role) {
            'editor' => __('editors'),
            'author' => __('authors'),
            default => __('contributors'),
        }, $roles);

        return match (count($words)) {
            1 => $words[0],
            2 => __(':first and :second', ['first' => $words[0], 'second' => $words[1]]),
            default => __(':first, :second and :third', ['first' => $words[0], 'second' => $words[1], 'third' => $words[2] ?? '']),
        };
    }

    /**
     * Makes each declared permission that has no row yet, and gives it to
     * administrators and the roles it names. A row that exists keeps who
     * holds it, a person's choice never undone by an update; it is given
     * only to a role newly agreed to, one whose consent line wasn't in the
     * consent before. Spatie's list is read again after commit.
     *
     * @param  list<array{id: string, label: string, description: string, roles: list<string>}>  $declared
     * @param  list<string>  $agreedBefore  the consent's permission lines before this one (consentLines)
     */
    public static function ensure(string $code, array $declared, array $agreedBefore = []): void
    {
        if ($declared === []) {
            return;
        }

        $guard = (string) config('auth.defaults.guard');
        $now = now();
        $names = array_map(fn (array $permission): string => self::name($code, $permission['id']), $declared);
        $existing = DB::table('permissions')->where('guard_name', $guard)->whereIn('name', $names)->pluck('name')->all();

        /** @var array<string, int> $roles */
        $roles = DB::table('roles')->where('guard_name', $guard)->pluck('id', 'name')->all();
        $grants = [];

        foreach ($declared as $permission) {
            $name = self::name($code, $permission['id']);

            if (in_array($name, $existing, true)) {
                $id = (int) DB::table('permissions')->where('guard_name', $guard)->where('name', $name)->value('id');

                foreach ($permission['roles'] as $role) {
                    if (isset($roles[$role]) && ! in_array("{$permission['id']}:{$role}", $agreedBefore, true)
                        && ! DB::table('role_has_permissions')->where('permission_id', $id)->where('role_id', $roles[$role])->exists()) {
                        $grants[] = ['permission_id' => $id, 'role_id' => $roles[$role]];
                    }
                }

                continue;
            }

            $id = DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => $guard, 'created_at' => $now, 'updated_at' => $now]);

            foreach ([self::ADMINISTRATOR, ...$permission['roles']] as $role) {
                if (isset($roles[$role])) {
                    $grants[] = ['permission_id' => $id, 'role_id' => $roles[$role]];
                }
            }
        }

        if ($grants !== []) {
            DB::table('role_has_permissions')->insert($grants);
        }

        DB::afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());
    }

    /**
     * Removes every permission of the plugin; their grants to roles and to
     * people go with them (the pivot rows cascade).
     */
    public static function remove(string $code): void
    {
        DB::table('permissions')
            ->where('guard_name', (string) config('auth.defaults.guard'))
            ->where('name', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], "plugin.{$code}.").'%')
            ->delete();

        DB::afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());
    }
}
