<?php

/**
 * A content type's own six permissions (I-413): their names, who holds
 * them, and making and removing them. The type Operations write through it,
 * inside their transaction and under the types' lock, with the query
 * builder only: Spatie's models would reload and cache the permission list
 * from inside the transaction.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Types;

use App\Domain\Users\Roles;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

final class TypePermissions
{
    /**
     * The six names a type with its own permissions has, action => name,
     * in ContentType::ACTIONS' order.
     *
     * @return array<string, string>
     */
    public static function names(string $key): array
    {
        $names = [];

        foreach (array_keys(ContentType::ACTIONS) as $action) {
            $names[$action] = "{$key}.{$action}";
        }

        return $names;
    }

    /**
     * Which of the type's six names exist already, in names()' order.
     *
     * @return list<string>
     */
    public static function existing(string $key): array
    {
        return self::present(array_values(self::names($key)));
    }

    /**
     * Which of $names have a permission row of the default guard, in
     * $names' order: what Spatie can find once its list is read again.
     *
     * @param  list<string>  $names
     *
     * @return list<string>
     */
    public static function present(array $names): array
    {
        $found = DB::table('permissions')
            ->where('guard_name', (string) config('auth.defaults.guard'))
            ->whereIn('name', $names)
            ->pluck('name')
            ->all();

        return array_values(array_intersect($names, $found));
    }

    /**
     * Which of $names Spatie can find in the list it reads, in $names' order.
     * The list can lag the table: a process that read it before a type got
     * its own six may write it back after the forget, so a caller that hands
     * a name to Spatie asks here, not the table.
     *
     * @param  list<string>  $names
     *
     * @return list<string>
     */
    public static function findable(array $names): array
    {
        $guard = (string) config('auth.defaults.guard');
        $registrar = app(PermissionRegistrar::class);

        return array_values(array_filter(
            $names,
            fn (string $name): bool => $registrar->getPermissions(['name' => $name, 'guard_name' => $guard], true)->isNotEmpty(),
        ));
    }

    /**
     * What each role on the site holds of the abilities $abilities names,
     * role name => the actions, in ContentType::ACTIONS' order. Every role
     * of the default guard is present, in Roles::LABELS' order and then by
     * name, even when it holds none.
     *
     * @param  array<string, string>  $abilities  action => ability name
     *
     * @return array<string, list<string>>
     */
    public static function held(array $abilities): array
    {
        $guard = (string) config('auth.defaults.guard');
        $labelled = array_flip(array_keys(Roles::LABELS));

        /** @var list<string> $roles */
        $roles = DB::table('roles')
            ->where('guard_name', $guard)
            ->pluck('name')
            ->map(fn (mixed $name): string => (string) $name)
            ->all();

        usort($roles, function (string $left, string $right) use ($labelled): int {
            $leftKnown = array_key_exists($left, $labelled);
            $rightKnown = array_key_exists($right, $labelled);

            if ($leftKnown && $rightKnown) {
                return $labelled[$left] <=> $labelled[$right];
            }

            if ($leftKnown) {
                return -1;
            }

            if ($rightKnown) {
                return 1;
            }

            return strcmp($left, $right);
        });

        /** @var array<string, list<string>> $actionsFor */
        $actionsFor = [];

        foreach ($abilities as $action => $ability) {
            $actionsFor[$ability][] = $action;
        }

        $rows = DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('roles.guard_name', $guard)
            ->where('permissions.guard_name', $guard)
            ->whereIn('permissions.name', array_values($abilities))
            ->get(['roles.name as role', 'permissions.name as permission']);

        /** @var array<string, array<string, true>> $found */
        $found = [];

        foreach ($rows as $row) {
            foreach ($actionsFor[(string) $row->permission] ?? [] as $action) {
                $found[(string) $row->role][$action] = true;
            }
        }

        $held = [];

        foreach ($roles as $role) {
            $actions = [];

            foreach (array_keys(ContentType::ACTIONS) as $action) {
                if (isset($found[$role][$action])) {
                    $actions[] = $action;
                }
            }

            $held[$role] = $actions;
        }

        return $held;
    }

    /**
     * Whether any role that both $grants names and the site has holds a
     * different set of the type's actions than $grants gives it.
     *
     * @param  array<string, list<string>>  $grants  role name => actions
     */
    public static function differs(string $key, array $grants): bool
    {
        $held = self::held(self::names($key));

        foreach ($grants as $role => $actions) {
            if (! array_key_exists((string) $role, $held)) {
                continue;
            }

            $given = $actions;
            $has = $held[(string) $role];
            sort($given);
            sort($has);

            if ($given !== $has) {
                return true;
            }
        }

        return false;
    }

    /**
     * Makes the type's six and grants them: each role $grants names gets the
     * actions given; every other role, what it holds of content.* now. A role
     * the site doesn't have is skipped; none is made.
     *
     * @param  array<string, list<string>>  $grants  role name => actions
     */
    public static function make(string $key, array $grants = []): void
    {
        $guard = (string) config('auth.defaults.guard');
        $names = self::names($key);
        $now = now();
        $starting = self::held(ContentType::ACTIONS);

        DB::table('permissions')->insert(array_map(
            fn (string $name): array => ['name' => $name, 'guard_name' => $guard, 'created_at' => $now, 'updated_at' => $now],
            array_values($names),
        ));

        /** @var array<string, int> $ids */
        $ids = DB::table('permissions')->where('guard_name', $guard)->whereIn('name', array_values($names))->pluck('id', 'name')->all();
        /** @var array<string, int> $roles */
        $roles = DB::table('roles')->where('guard_name', $guard)->pluck('id', 'name')->all();
        $rows = [];

        foreach ($roles as $role => $roleId) {
            foreach ($grants[$role] ?? $starting[$role] ?? [] as $action) {
                $rows[] = ['permission_id' => $ids[$names[$action]], 'role_id' => $roleId];
            }
        }

        if ($rows !== []) {
            DB::table('role_has_permissions')->insert($rows);
        }
    }

    /**
     * Removes the type's six; their grants to roles and to people go with
     * them (the pivot rows cascade). Called only for a type whose row says it
     * has its own, and making them refuses names that exist, so these six
     * are the type's alone.
     */
    public static function remove(string $key): void
    {
        DB::table('permissions')
            ->where('guard_name', (string) config('auth.defaults.guard'))
            ->whereIn('name', array_values(self::names($key)))
            ->delete();
    }
}
