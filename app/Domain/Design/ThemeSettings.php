<?php

declare(strict_types=1);

namespace App\Domain\Design;

use App\Domain\Cache\PageTags;
use App\Domain\Menus\ThemeSlotSettings;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Themes\Manifest;
use App\Domain\Themes\ThemeRegistry;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;

/**
 * Registers each usable theme's `theme.{code}` settings group (spec §5.9):
 * the settings its manifest declares, in manifest order, then its navigation
 * slots, as ThemeSlotSettings builds them. Site › Themes is its screen.
 */
final readonly class ThemeSettings
{
    public function __construct(private SettingsRegistry $registry) {}

    /**
     * Builds the group of each usable theme that declares settings or
     * navigation slots, through replace(), so running it again, as tests do
     * after changing the theme paths, rebuilds them.
     */
    public function register(ThemeRegistry $themes): void
    {
        foreach ($themes->all() as $theme) {
            $manifest = $theme->usable() ? $theme->manifest : null;

            if ($manifest === null) {
                continue;
            }

            $settings = [
                ...array_map($this->setting(...), $manifest->settings()),
                ...ThemeSlotSettings::settings($manifest),
            ];

            if ($settings === []) {
                continue;
            }

            $name = $manifest->name();

            $this->registry->replace(new SettingsGroup(
                key: ThemeSlotSettings::groupKey($theme->code),
                label: $name,
                description: "The {$name} theme's own settings, and which menu each place in it shows.",
                section: 'site',
                ability: 'settings.design.manage',
                settings: $settings,
                since: '0.1.0',
                cacheTags: [PageTags::THEME],
                screen: 'themes.index',
            ));
        }
    }

    /**
     * One manifest setting as a declaration. ManifestValidator has checked
     * it, so the theme is usable only when this can't throw; a setting
     * without a description is described by its label.
     *
     * @param  array<string, mixed>  $declared
     */
    private function setting(array $declared): Setting
    {
        $key = (string) $declared['key'];
        $label = (string) $declared['label'];
        $description = (string) ($declared['description'] ?? $label);
        $default = $declared['default'];

        $setting = match ($declared['type']) {
            'select' => Setting::select($key, $label, $description, '0.1.0', array_map(strval(...), (array) $declared['options']))->default((string) $default),
            'boolean' => Setting::boolean($key, $label, $description, '0.1.0')->default((bool) $default),
            'color' => Setting::color($key, $label, $description, '0.1.0')->default(strtolower((string) $default)),
            default => Setting::text($key, $label, $description, '0.1.0')->default((string) $default),
        };

        $setting = $setting->fieldset((string) $declared['fieldset']);

        return isset($declared['visible_when']) ? $setting->visibleWhen((array) $declared['visible_when']) : $setting;
    }
}
