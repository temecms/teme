<?php

/**
 * What a menu's Show in card needs for "For readers of"
 * (docs/proposals/multilingual-screens.md, 5): the site's languages, the
 * menu's own, and the menu each of the theme's places shows to each other
 * language's readers. It reads only; BindNavigationSlot writes.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Languages\Queries;

use App\Domain\Languages\SiteLanguages;
use App\Domain\Menus\ThemeSlotSettings;
use App\Domain\Settings\Models\SettingsGroupRecord;
use Illuminate\Support\Facades\DB;

final class MenuLanguages
{
    /**
     * The languages, in order; the menu's language by its tag; and, by
     * other language's tag, each place's menu for its readers (null where
     * the place shows the default language's menu). Nothing on a site with
     * one language.
     *
     * @param  list<string>  $slots
     *
     * @return array{languages: list<array{tag: string, name: string}>, language: string|null, bindings: array<string, array<string, string|null>>}
     */
    public static function for(string $navigationId, ?string $themeCode, array $slots): array
    {
        $languages = app(SiteLanguages::class);

        if (! $languages->isMultilingual()) {
            return ['languages' => [], 'language' => null, 'bindings' => []];
        }

        $stored = DB::table('navigations')->where('id', $navigationId)->value('language');
        $words = $themeCode === null ? [] : (array) (SettingsGroupRecord::query()->find(ThemeSlotSettings::groupKey($themeCode))->translations ?? []);
        $bindings = [];

        foreach ($languages->others() as $tag) {
            foreach ($slots as $slot) {
                $chosen = $words[$tag][ThemeSlotSettings::settingKey($slot)] ?? null;
                $bindings[$tag][$slot] = is_string($chosen) && $chosen !== '' ? $chosen : null;
            }
        }

        return [
            // compact(): the key is a language's tag, never the taxonomy (NoTypeLiteralsTest).
            'languages' => array_map(fn (string $tag): array => compact('tag') + ['name' => $languages->label($tag)], $languages->all()),
            'language' => $languages->of(is_string($stored) ? $stored : null),
            'bindings' => $bindings,
        ];
    }
}
