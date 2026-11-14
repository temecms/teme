<?php

declare(strict_types=1);

namespace App\Domain\Themes;

use App\Domain\Cache\PageTags;
use App\Domain\Settings\SettingsRegistry;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;

/**
 * The `design` settings group: which theme the public site is drawn with,
 * chosen from the usable themes, and the site's accent colour, which is
 * empty to use the theme's own. It is built from the themes found, so it
 * registers through replace() and can be built again when they change.
 * Site › Themes is its screen, and its Operations write it.
 */
final class DesignSettingsGroup
{
    public static function register(SettingsRegistry $registry, ThemeRegistry $themes): void
    {
        $options = [];

        foreach ($themes->all() as $theme) {
            if ($theme->usable() && $theme->manifest !== null) {
                $options[$theme->code] = $theme->manifest->name();
            }
        }

        // A select needs an option: with no usable theme the site runs on
        // core's fallback, and the group still builds.
        if ($options === []) {
            $options = ['default' => 'Teme Default'];
        }

        $registry->replace(new SettingsGroup(
            key: 'design',
            label: 'Design',
            description: 'How the public site looks: the theme it is drawn with, and the brand colour.',
            section: 'site',
            ability: 'settings.design.manage',
            settings: [
                Setting::select('theme', 'Theme', 'The theme the public site is drawn with. A theme that can\'t be used gives visitors plain pages until it is fixed.', '0.1.0', $options)->default('default'),
                Setting::color('accent', 'Brand colour', 'The colour of links and buttons on the public site. Themes read it but don’t own it, so it stays when you change theme. Empty uses the theme’s own.', '0.1.0'),
            ],
            since: '0.1.0',
            cacheTags: [PageTags::THEME],
            screen: 'themes.index',
        ));
    }
}
