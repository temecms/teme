<?php

declare(strict_types=1);

namespace App\Domain\Seo;

use App\Domain\Cache\PageTags;
use InvalidArgumentException;
use Teme\Contracts\Settings\SettingsGroup;
use UnexpectedValueException;

/**
 * Settings › Search and social, the `seo` group (docs/studies/seo.md §3.14):
 * one group, one row and one ability, built from every section registered
 * under SECTIONS, in the sections' own order. A page that reads any of its
 * settings carries its tag, so a change removes the page.
 */
final class SearchAndSocial
{
    public const string KEY = 'seo';

    /** The container tag a section is registered under, from its part's own provider's register(). */
    public const string SECTIONS = 'teme.seo.sections';

    /** Administrators' (I-850). */
    public const string ABILITY = 'settings.seo.manage';

    /**
     * The sections, lowest order first, each checked: a key of lower-case
     * words joined by hyphens, and no key or order twice.
     *
     * @param  iterable<mixed>  $sections
     *
     * @return list<SearchAndSocialSection>
     */
    public static function ordered(iterable $sections): array
    {
        $ordered = [];
        $keys = [];
        $orders = [];

        foreach ($sections as $section) {
            if (! $section instanceof SearchAndSocialSection) {
                throw new UnexpectedValueException('Registered as a SearchAndSocialSection, but '.get_debug_type($section).' isn\'t one.');
            }

            $key = $section->key();
            $order = $section->order();

            if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $key) !== 1) {
                throw new InvalidArgumentException("Search and social section [{$key}] needs a key of lower-case words joined by hyphens.");
            }

            if (isset($keys[$key])) {
                throw new InvalidArgumentException("Two Search and social sections have the key [{$key}].");
            }

            if (isset($orders[$order])) {
                throw new InvalidArgumentException("Search and social sections [{$orders[$order]}] and [{$key}] share the order {$order}.");
            }

            $keys[$key] = true;
            $orders[$order] = $key;
            $ordered[] = $section;
        }

        usort($ordered, fn (SearchAndSocialSection $one, SearchAndSocialSection $other): int => $one->order() <=> $other->order());

        return $ordered;
    }

    /**
     * The group, its settings section by section. The registry refuses a
     * setting two sections declare, as it does within any group.
     *
     * @param  iterable<mixed>  $sections
     */
    public static function group(iterable $sections): SettingsGroup
    {
        $settings = [];

        foreach (self::ordered($sections) as $section) {
            foreach ($section->settings() as $setting) {
                $settings[] = $setting;
            }
        }

        return new SettingsGroup(
            key: self::KEY,
            label: 'Search and social',
            description: 'Titles in search results, what search engines may list, social cards, feeds, robots.txt, the API and llms.txt, and the 404 log.',
            section: self::KEY,
            ability: self::ABILITY,
            settings: $settings,
            since: '0.1.0',
            cacheTags: [PageTags::SEO],
        );
    }
}
