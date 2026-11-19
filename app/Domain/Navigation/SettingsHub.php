<?php

/**
 * The Settings hub: the menu's Settings opens it, it lists every Settings
 * page for the palette, and its own page draws a card for each, under the
 * hub's headings, a plugin's last under "From plugins".
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Navigation;

use App\Domain\Plugins\PluginRegistry;
use App\Domain\Settings\Queries\SettingsNavigation;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;

final readonly class SettingsHub implements NavHub
{
    /**
     * Over plugins' pages, which come last.
     *
     * @since 1.2.0
     */
    public const string PLUGINS = 'From plugins';

    /**
     * Core's pages: the heading each sits under, its icon (a Lucide name)
     * and its line. A page this list doesn't name sits under The site with
     * its group's own description.
     *
     * @var array<string, array{heading: string, icon: string, line: string}>
     */
    private const array CARDS = [
        'general' => ['heading' => 'The site', 'icon' => 'settings', 'line' => 'The site’s name, tagline, time zone and language.'],
        'reading' => ['heading' => 'The site', 'icon' => 'eye', 'line' => 'What the home page shows, and how many items each listing holds.'],
        'addresses' => ['heading' => 'The site', 'icon' => 'link', 'line' => 'The public addresses of every type, taxonomy and author.'],
        'seo' => ['heading' => 'The site', 'icon' => 'search', 'line' => 'Titles in search results, social cards, feeds and robots.txt.'],
        'languages' => ['heading' => 'The site', 'icon' => 'globe', 'line' => 'The languages the site publishes in.'],
        'site-code' => ['heading' => 'The site', 'icon' => 'code', 'line' => 'Code added to every public page, such as analytics.'],
        'updates' => ['heading' => 'The site', 'icon' => 'refresh-cw', 'line' => 'How Teme updates itself.'],
        'writing' => ['heading' => 'Writing and discussion', 'icon' => 'file', 'line' => 'How long the trash keeps items and comments.'],
        'discussion' => ['heading' => 'Writing and discussion', 'icon' => 'message-square', 'line' => 'Whether the site has comments, and who may comment.'],
        'media' => ['heading' => 'Writing and discussion', 'icon' => 'image', 'line' => 'Upload limits, and the image widths Teme makes.'],
        'fediverse' => ['heading' => 'Writing and discussion', 'icon' => 'radio', 'line' => 'Followers on Mastodon and other fediverse servers.'],
        'people' => ['heading' => 'People and services', 'icon' => 'users', 'line' => 'How everyone with an account signs in.'],
        'ai' => ['heading' => 'People and services', 'icon' => 'wand-sparkles', 'line' => 'The AI services Teme may use, and the limits.'],
        'management_api' => ['heading' => 'People and services', 'icon' => 'boxes', 'line' => 'Tokens for programs that manage the site.'],
    ];

    public function __construct(private SettingsNavigation $settings, private SettingsRegistry $registry, private PluginRegistry $plugins) {}

    /**
     * The hub's own address, or null when the person may open no Settings
     * page, so the menu leaves Settings out.
     */
    public function firstHrefFor(User $user): ?string
    {
        return $this->pagesFor($user) === [] ? null : route('settings.index', [], false);
    }

    /**
     * The Settings pages this person may open, in the Settings menu's
     * order, each drawn with the Settings icon, a plugin's page with the
     * plugin's label. A page has no line of its own.
     *
     * @return list<array{key: string, label: string, icon: string, description: string, href: string, plugin?: string}>
     */
    public function pagesFor(User $user): array
    {
        return array_map(fn (array $page): array => [
            'key' => $page['key'],
            'label' => $page['label'],
            'icon' => 'settings',
            'description' => '',
            'href' => $page['href'],
            ...($page['plugin'] ? ['plugin' => $page['label']] : []),
        ], $this->settings->forUser($user->getKey()));
    }

    /**
     * The hub page's cards for this person, in the menu's order: key, name,
     * address, icon, line, heading, and the plugin's name for a plugin's
     * page (null for core's). Empty when the person may open none.
     *
     * @return list<array{key: string, label: string, href: string, icon: string, line: string, heading: string, plugin: string|null}>
     *
     * @since 1.2.0
     */
    public function cardsFor(User $user): array
    {
        $cards = [];

        foreach ($this->settings->forUser($user->getKey()) as $page) {
            $known = self::CARDS[$page['key']] ?? null;
            $line = $known === null ? ($this->registry->find($page['key'])->description ?? '') : __($known['line']);
            $plugin = null;

            if ($page['plugin']) {
                $owner = $this->registry->ownerOf($page['key']);
                $plugin = ($owner === null ? null : $this->plugins->find($owner)?->manifest?->name()) ?? $page['label'];
            }

            $cards[] = [
                'key' => $page['key'],
                'label' => $page['label'],
                'href' => $page['href'],
                'icon' => $page['plugin'] ? 'plug' : ($known['icon'] ?? 'settings'),
                'line' => $line,
                'heading' => __($page['plugin'] ? self::PLUGINS : ($known['heading'] ?? 'The site')),
                'plugin' => $plugin,
            ];
        }

        return $cards;
    }
}
