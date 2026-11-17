<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Area;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Admin\AreaContext;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Builds what a plugin's area is told about the person it answers: who
 * they are, the admin's language, the site's time zone, and which of the
 * plugin's own permissions they hold.
 */
final class AreaContexts
{
    /**
     * The context for this person and this plugin, now.
     */
    public static function for(string $code, string $personId): AreaContext
    {
        return new AreaContext($personId, app()->getLocale(), app(SettingsReader::class)->string('general', 'timezone'), self::held($code, $personId));
    }

    /**
     * The ids of the plugin's permissions the person holds, in name order.
     *
     * @return list<string>
     */
    private static function held(string $code, string $personId): array
    {
        $person = User::query()->find($personId);
        $prefix = "plugin.{$code}.";

        if ($person === null) {
            return [];
        }

        $names = DB::table('permissions')
            ->where('guard_name', (string) config('auth.defaults.guard'))
            ->where('name', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix).'%')
            ->orderBy('name')
            ->pluck('name')
            ->all();

        return array_values(array_map(
            fn (string $name): string => substr($name, strlen($prefix)),
            array_filter(array_map(strval(...), $names), fn (string $name): bool => $person->can($name)),
        ));
    }
}
