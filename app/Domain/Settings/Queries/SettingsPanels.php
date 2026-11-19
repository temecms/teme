<?php

/**
 * The panels a Settings page splits its group into, where it splits it at
 * all (I-3128): core's pages as the approved design draws them, and a
 * plugin's declared sections
 * (docs/plans/plugin-sockets-2.md, A4). Every other group page is one
 * panel. Every setting of a split group is in exactly one
 * (SettingsPanelsDataTest).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Settings\Queries;

use App\Domain\Plugins\PluginRegistry;
use App\Domain\Settings\SettingsRegistry;
use Teme\Contracts\Settings\Setting;

final class SettingsPanels
{
    /**
     * Core's pages split into sections, by the page's key, as the approved
     * design draws them. General's brand colour, Reading's home page and
     * Media's image widths are sections the page draws itself.
     *
     * @var array<string, list<array{title: string, description: string, keys: list<string>}>>
     *
     * @since 1.2.0
     */
    public const array CORE = [
        'general' => [
            ['title' => 'Site', 'description' => 'How the site introduces itself.', 'keys' => ['site_title', 'tagline']],
            ['title' => 'Contact', 'description' => 'Who hears from Teme.', 'keys' => ['admin_email']],
            ['title' => 'Time and language', 'description' => '', 'keys' => ['timezone', 'language']],
        ],
        'writing' => [
            ['title' => 'Trash', 'description' => 'Posts, pages and comments moved to the trash wait here before they are deleted for good.', 'keys' => ['trash_days']],
        ],
        'reading' => [
            ['title' => 'Listings', 'description' => 'The lists readers page through: the latest posts, an archive, search results.', 'keys' => ['posts_per_page', 'home_types']],
        ],
        'media' => [
            ['title' => 'Uploads', 'description' => 'What people may add to the library.', 'keys' => ['max_upload_mb', 'max_megapixels']],
        ],
        'people' => [
            ['title' => 'Passwords', 'description' => 'What a new password must be.', 'keys' => ['password_min_length', 'password_check_breached']],
            ['title' => 'Sign-in limits', 'description' => 'How many wrong passwords are allowed before Teme makes someone wait.', 'keys' => ['login_attempts_per_account_ip', 'login_attempts_per_ip', 'login_untrusted_attempts_per_account']],
            ['title' => 'Sessions', 'description' => 'How long someone stays signed in.', 'keys' => ['session_idle_minutes', 'session_absolute_hours', 'session_remember_days', 'elevation_minutes']],
            ['title' => 'Two-factor authentication', 'description' => '', 'keys' => ['two_factor_required_roles']],
        ],
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

        return self::CORE[$page] ?? null;
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
