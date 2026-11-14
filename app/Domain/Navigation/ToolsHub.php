<?php

declare(strict_types=1);

namespace App\Domain\Navigation;

use App\Domain\Plugins\Contributions\ToolLink;
use App\Domain\Plugins\Contributions\ToolLinks;
use App\Domain\Plugins\PluginAccess;
use App\Models\User;

/**
 * The Tools hub (I-220, I-467): the utilities for looking after the site,
 * each behind an ability of its own, then the links plugins add (I-3353).
 * The menu's Tools entry leads to the hub's page, and isn't shown to
 * someone who may open none of them.
 */
final readonly class ToolsHub implements NavHub
{
    public function __construct(private ToolLinks $links) {}

    /**
     * Returns the tools this person may open, in the hub's order, then the
     * plugins' links by label, each with its key, label, icon, description,
     * address (relative, or a plugin's outside one) and whether it leads to
     * another site.
     *
     * @return list<array{key: string, label: string, icon: string, description: string, href: string, external: bool}>
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

        if (! $user->can(PluginAccess::MANAGE)) {
            return $pages;
        }

        $links = $this->links->all();
        usort($links, fn (ToolLink $a, ToolLink $b): int => strnatcasecmp($a->label, $b->label));

        foreach ($links as $link) {
            $external = $link->to !== 'settings';

            $pages[] = [
                'key' => "plugin.{$link->code}.{$link->id}",
                'label' => $link->label,
                'icon' => $link->icon,
                'description' => $link->description,
                'href' => $external ? $link->to : route('settings.show', ['group' => "plugin.{$link->code}"], false),
                'external' => $external,
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
                'icon' => 'list-checks',
                'description' => __('Imports and other long tasks: progress, failures and retries.'),
                'route' => 'tools.jobs.index',
                'ability' => 'jobs.manage',
            ],
            [
                'key' => 'import',
                'label' => __('Import'),
                'icon' => 'import',
                'description' => __('Move a WordPress site into Teme, and see what came across.'),
                'route' => 'tools.importer.index',
                'ability' => 'imports.run',
            ],
            [
                'key' => 'backups',
                'label' => __('Backups'),
                'icon' => 'archive',
                'description' => __('Encrypted copies of the site, where they’re kept, and whether they can be read back.'),
                'route' => 'tools.backups.index',
                'ability' => 'backups.manage',
            ],
            [
                'key' => 'updates',
                'label' => __('Updates'),
                'icon' => 'refresh-cw',
                'description' => __('Which Teme this site runs, the newest release, and what changed.'),
                'route' => 'tools.updates.index',
                'ability' => 'updates.manage',
            ],
            [
                'key' => 'page-cache',
                'label' => __('Page cache'),
                'icon' => 'layers',
                'description' => __('Saved copies of public pages, and clearing them when one looks out of date.'),
                'route' => 'tools.page-cache.show',
                'ability' => 'page-cache.clear',
            ],
            [
                'key' => 'redirects',
                'label' => __('Redirects'),
                'icon' => 'link',
                'description' => __('Send old addresses on to new ones, and see which addresses readers couldn’t find.'),
                'route' => 'tools.redirects.index',
                'ability' => 'redirects.manage',
            ],
            [
                'key' => 'ai-activity',
                'label' => __('AI activity'),
                'icon' => 'sparkles',
                'description' => __('Every request people made to AI, what it cost, and what became of each.'),
                'route' => 'tools.ai-activity.index',
                'ability' => 'ai-activity.view',
            ],
        ];
    }
}
