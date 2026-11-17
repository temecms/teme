<?php

declare(strict_types=1);

namespace App\Domain\Settings\Queries;

use App\Domain\Plugins\PluginRegistry;
use App\Domain\Settings\SettingsRegistry;
use Teme\Contracts\Settings\Setting;

/**
 * The panels a Settings page splits its group into, where it splits it at
 * all (I-3128): General's two, and a plugin's declared sections
 * (docs/plans/plugin-sockets-2.md, A4). Every other group page is one
 * panel. Every setting of a split group is in exactly one
 * (SettingsPanelsDataTest).
 */
final class SettingsPanels
{
    /** @var list<array{title: string, description: string, keys: list<string>}> */
    public const array GENERAL = [
        ['title' => 'The site', 'description' => 'Its name and tagline, and where Teme sends notices.', 'keys' => ['site_title', 'tagline', 'admin_email']],
        ['title' => 'Time and language', 'description' => 'How dates are shown and scheduled, and the language the site is written in.', 'keys' => ['timezone', 'language']],
    ];

    /**
     * The page's panels, each its title, its line and its settings' keys in
     * order, or null for a page drawn as one panel.
     *
     * @return list<array{title: string, description: string, keys: list<string>}>|null
     */
    public static function for(string $page): ?array
    {
        if (str_starts_with($page, 'plugin.')) {
            return self::plugin($page);
        }

        return $page === 'general' ? self::GENERAL : null;
    }

    /**
     * A plugin group's sections in declared order, each with its settings,
     * those without a section first in a panel with no title; null for a
     * plugin that declares no sections.
     *
     * @return list<array{title: string, description: string, keys: list<string>}>|null
     */
    private static function plugin(string $group): ?array
    {
        $registry = app(SettingsRegistry::class);
        $settings = $registry->find($group)->settings ?? [];
        $owner = $registry->ownerOf($group);
        $declared = $owner === null ? null : (app(PluginRegistry::class)->find($owner)?->manifest?->contributes()['settingsSections'] ?? null);

        if (! is_array($declared) || $declared === [] || $settings === []) {
            return null;
        }

        $loose = array_values(array_map(fn (Setting $setting): string => $setting->key, array_filter($settings, fn (Setting $setting): bool => $setting->section === null)));
        $panels = $loose === [] ? [] : [['title' => '', 'description' => '', 'keys' => $loose]];

        foreach ($declared as $section) {
            if (! is_array($section) || ! is_string($section['id'] ?? null)) {
                continue;
            }

            $keys = array_values(array_map(fn (Setting $setting): string => $setting->key, array_filter($settings, fn (Setting $setting): bool => $setting->section === $section['id'])));

            if ($keys !== []) {
                $panels[] = [
                    'title' => is_string($section['label'] ?? null) ? $section['label'] : '',
                    'description' => is_string($section['description'] ?? null) ? $section['description'] : '',
                    'keys' => $keys,
                ];
            }
        }

        return $panels;
    }
}
