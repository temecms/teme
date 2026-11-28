<?php

/**
 * The `design` settings group: which theme the public site is drawn with,
 * chosen from the usable themes, and the site's brand colour (`design.brand`),
 * empty to leave `--teme-brand` unset. It is built from the themes found, so
 * it registers through replace() and can be built again when they change.
 * Site › Themes is its screen, and its Operations write it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Themes;

use App\Domain\Cache\PageTags;
use App\Domain\Settings\SettingsRegistry;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;

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
            description: 'How the public site looks: the theme it is drawn with, the brand colour, and the site icon.',
            section: 'site',
            ability: 'settings.design.manage',
            settings: [
                Setting::select('theme', 'Theme', 'The theme the public site is drawn with. A theme that can\'t be used gives visitors plain pages until it is fixed.', '0.1.0', $options)->default('default'),
                Setting::color('brand', 'Brand colour', 'The site\'s identity colour: the browser tab, and --teme-brand for themes. Empty leaves the token unset. A theme may opt to use it as its accent.', '1.8.0'),
                Setting::media('site_icon', 'Site icon', 'The favicon and home-screen icon. Empty uses the active theme’s default, when it has one.', '1.8.0'),
            ],
            since: '0.1.0',
            // Theme and accent refresh theme-tagged pages. site_icon alone
            // also purges everything (SiteIconSizes), because every head shows it.
            cacheTags: [PageTags::THEME],
            screen: 'themes.index',
        ));
    }
}
