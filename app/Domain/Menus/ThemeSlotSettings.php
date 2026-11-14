<?php

declare(strict_types=1);

namespace App\Domain\Menus;

use App\Domain\Settings\SettingsRegistry;
use App\Domain\Themes\Manifest;
use App\Domain\Themes\Theme;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsReader;

/**
 * A theme's navigation slots as its settings: each theme's group,
 * `theme.{code}` (spec §5.9), which ThemeSettings registers, holds one text
 * setting per slot it declares, `nav_{slot}`, with the ID of the menu shown
 * there, or '' for none.
 */
final readonly class ThemeSlotSettings
{
    public const string GROUP_PREFIX = 'theme.';

    public const string SETTING_PREFIX = 'nav_';

    public function __construct(
        private SettingsRegistry $registry,
        private SettingsReader $settings,
    ) {}

    /**
     * A theme's settings group key, such as "theme.good".
     */
    public static function groupKey(string $themeCode): string
    {
        return self::GROUP_PREFIX.$themeCode;
    }

    /**
     * A slot's setting key, such as "nav_primary": a slot may have hyphens,
     * which a setting key may not.
     */
    public static function settingKey(string $slot): string
    {
        return self::SETTING_PREFIX.str_replace('-', '_', $slot);
    }

    /**
     * The theme's slot settings, which ThemeSettings registers in its
     * `theme.{code}` group after the manifest's own: one text setting per
     * slot, in manifest order. A text setting, not a select of menus: a
     * select's options are fixed at boot, and boot reads no database (1.6b).
     *
     * @return list<Setting>
     */
    public static function settings(Manifest $manifest): array
    {
        $slots = $manifest->navigations();

        return array_map(
            fn (string $slot, string $label): Setting => Setting::text(self::settingKey($slot), $label, 'The menu shown in this place.', '0.1.0'),
            array_keys($slots),
            $slots,
        );
    }

    /**
     * Each slot the theme declares, in its manifest's order, with the ID of
     * the menu shown there, or null for none.
     *
     * @return array<string, string|null>
     */
    public function bound(Theme $theme): array
    {
        $bound = [];

        foreach (array_keys($theme->manifest?->navigations() ?? []) as $slot) {
            $id = $this->registry->find(self::groupKey($theme->code)) === null ? '' : $this->settings->string(self::groupKey($theme->code), self::settingKey($slot));
            $bound[$slot] = $id === '' ? null : $id;
        }

        return $bound;
    }

    /**
     * Every slot showing this menu, in any installed theme: group key =>
     * its setting keys.
     *
     * @return array<string, list<string>>
     */
    public function showing(string $navigationId): array
    {
        $showing = [];

        foreach ($this->registry->all() as $group) {
            if (! str_starts_with($group->key, self::GROUP_PREFIX)) {
                continue;
            }

            foreach ($group->settings as $setting) {
                if (str_starts_with($setting->key, self::SETTING_PREFIX) && $this->settings->string($group->key, $setting->key) === $navigationId) {
                    $showing[$group->key][] = $setting->key;
                }
            }
        }

        return $showing;
    }
}
