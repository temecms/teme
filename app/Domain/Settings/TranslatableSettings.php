<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Domain\Menus\ThemeSlotSettings;
use App\Domain\Seo\SearchAndSocial;

/**
 * Which settings are words a site writes once per language (I-3360): the
 * site title and tagline, Search and social's title templates, and the menu
 * each theme slot shows. Every other setting has one value for every
 * language. Their other languages' values are kept in the group row's
 * `translations`, and read over the default's on a page in that language.
 */
final class TranslatableSettings
{
    /** The general group's words. */
    private const array GENERAL = ['site_title', 'tagline'];

    /**
     * Whether this group's setting has words per language.
     */
    public static function has(string $group, string $key): bool
    {
        return match (true) {
            $group === 'general' => in_array($key, self::GENERAL, true),
            // Every title template, never the separator between their parts.
            $group === SearchAndSocial::KEY => str_starts_with($key, 'title_') && $key !== 'title_separator',
            // A theme's slots, `nav_{slot}`, never the theme's own design settings.
            str_starts_with($group, ThemeSlotSettings::GROUP_PREFIX) => str_starts_with($key, ThemeSlotSettings::SETTING_PREFIX),
            default => false,
        };
    }

    /**
     * The keys of a group's settings that have words per language, in the
     * group's order.
     *
     * @param  list<string>  $keys  every key the group declares
     *
     * @return list<string>
     */
    public static function among(string $group, array $keys): array
    {
        return array_values(array_filter($keys, fn (string $key): bool => self::has($group, $key)));
    }
}
