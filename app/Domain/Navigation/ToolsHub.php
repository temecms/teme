<?php

/**
 * The Tools hub (I-220, I-467): the utilities for looking after the site,
 * each behind an ability of its own, then plugins' areas the menu has no
 * room for, then the links plugins add (I-3353).
 * The menu's Tools entry leads to the hub's page, and isn't shown to
 * someone who may open none of them.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Navigation;

use App\Domain\Plugins\Area\AreaEntries;
use App\Domain\Plugins\Area\PluginAreas;
use App\Domain\Plugins\Contributions\ToolLink;
use App\Domain\Plugins\Contributions\ToolLinks;
use App\Domain\Plugins\PluginAccess;
use App\Models\User;

final readonly class ToolsHub implements NavHub
{
    public function __construct(private ToolLinks $links) {}

    /**
     * Returns the tools this person may open, in the hub's order, then the
     * plugins' links by label, each with its key, label, icon, description,
     * address (relative, or a plugin's outside one), whether it leads to
     * another site and, for a plugin's link, the plugin's label.
     *
     * @return list<array{key: string, label: string, icon: string, description: string, href: string, external: bool, plugin?: string}>
     */
    public function pagesFor(User $user): array
    {
        $pages = [];

        foreach ($this->pages() as $page) {
            if ($user->can($page['ability'])) {
                $pages[] = [
                    'key' => $page['key'],
                    'label' => $page['label'],
                    'icon' => $page['icon'],
                    'description' => $page['description'],
                    'href' => route($page['route'], [], false),
                    'external' => false,
                ];
            }
        }

        // Plugins' areas that aren't in the menu, each behind its own permission.
        $pages = [...$pages, ...app(AreaEntries::class)->toolCards($user)];

        if (! $user->can(PluginAccess::MANAGE)) {
            return $pages;
        }

        $links = $this->links->all();
        usort($links, fn (ToolLink $a, ToolLink $b): int => strnatcasecmp($a->label, $b->label));

        $areas = app(PluginAreas::class);

        foreach ($links as $link) {
            $external = $link->to !== 'settings';
            $settingsTab = ! $external && $areas->hasSettingsTab($link->code);

            $pages[] = [
                'key' => "plugin.{$link->code}.{$link->id}",
                'label' => $link->label,
                'icon' => $link->icon,
                'description' => $link->description,
                'href' => $external
                    ? $link->to
                    : ($settingsTab
                        ? route('plugins.area.settings', ['code' => $link->code], false)
                        : route('settings.show', ['group' => "plugin.{$link->code}"], false)),
                'external' => $external,
                ...($link->plugin !== '' ? ['plugin' => $link->plugin] : []),
            ];
        }

        return $pages;
    }

    /**
     * The hub's own address, or null when the person may open no tool, so
     * the menu leaves Tools out.
     */
    public function firstHrefFor(User $user): ?string
    {
        return $this->pagesFor($user) === [] ? null : route('tools.index', [], false);
    }

    /**
     * The crumb an inbox item shows for this tools route ("Tools › Backups"),
     * or null when the route is not one of the hub's pages.
     */
    public function placeFor(string $route): ?string
    {
        foreach ($this->pages() as $page) {
            $stem = preg_replace('/\.[^.]+$/', '', $page['route']) ?? $page['route'];

            if ($page['route'] === $route || str_starts_with($route, $stem.'.')) {
                return __('Tools › :tool', ['tool' => $page['label']]);
            }
        }

        return null;
    }

    /**
     * Core's tools, in the order the hub lists them.
     *
     * @return list<array{key: string, label: string, icon: string, description: string, route: string, ability: string}>
     */
    private function pages(): array
    {
        return [
            [
                'key' => 'jobs',
                'label' => __('Jobs'),
                'icon' => 'refresh-cw',
                'description' => __('Background work: progress, failures and retries.'),
                'route' => 'tools.jobs.index',
                'ability' => 'jobs.manage',
            ],
            [
                'key' => 'updates',
                'label' => __('Updates'),
                'icon' => 'download',
                'description' => __('The Teme this site runs, what is new, and updating from here.'),
                'route' => 'tools.updates.index',
                'ability' => 'updates.manage',
            ],
            [
                'key' => 'ai-activity',
                'label' => __('AI activity'),
                'icon' => 'wand-sparkles',
                'description' => __('Requests people made to AI, what they cost, and the month’s budget.'),
                'route' => 'tools.ai-activity.index',
                'ability' => 'ai-activity.view',
            ],
            [
                'key' => 'import',
                'label' => __('Import'),
                'icon' => 'upload',
                'description' => __('Move a WordPress site into Teme, and catch up with it later.'),
                'route' => 'tools.importer.index',
                'ability' => 'imports.run',
            ],
            [
                'key' => 'backups',
                'label' => __('Backups'),
                'icon' => 'download',
                'description' => __('Encrypted copies of the site, and whether they restore.'),
                'route' => 'tools.backups.index',
                'ability' => 'backups.manage',
            ],
            [
                'key' => 'page-cache',
                'label' => __('Page cache'),
                'icon' => 'layout-grid',
                'description' => __('Saved copies of public pages: clear, clear one address, or warm.'),
                'route' => 'tools.page-cache.show',
                'ability' => 'page-cache.clear',
            ],
            [
                'key' => 'redirects',
                'label' => __('Redirects'),
                'icon' => 'arrow-right',
                'description' => __('Send old addresses on to new ones; the 404 log.'),
                'route' => 'tools.redirects.index',
                'ability' => 'redirects.manage',
            ],
        ];
    }
}
