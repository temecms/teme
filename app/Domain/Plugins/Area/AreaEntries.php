<?php

/**
 * Where plugins' areas show in the admin's menu (docs/plans/plugin-sockets-2.md,
 * A2; I-216, I-3401): one entry each, under the area's own label, in the task
 * section it names, at most five site-wide in the order they were enabled; an
 * area that asks for Tools, or comes sixth or later, is a card on the Tools
 * hub (area label, plugin name as the badge). Budgets place entries; they
 * never refuse a plugin.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Area;

use App\Domain\Navigation\NavEntry;
use App\Domain\Navigation\NavSection;
use App\Models\User;

final readonly class AreaEntries
{
    /** The most plugin entries the menu holds. */
    public const int MOST_ENTRIES = 5;

    /** Where plugin entries start in a section's order, after core's own. */
    public const int ORDER = 900;

    public function __construct(private PluginAreas $areas) {}

    /**
     * The menu's plugin entries, each behind its area's permission.
     *
     * @return list<NavEntry>
     */
    public function navEntries(): array
    {
        $entries = [];

        foreach ($this->placed()['menu'] as $index => $plugin) {
            $entries[] = new NavEntry(
                key: "plugin.{$plugin->code}",
                label: $this->areaLabel($plugin),
                icon: $plugin->area->icon,
                section: $plugin->area->menu === 'content' ? NavSection::Content : NavSection::Site,
                order: self::ORDER + $index,
                route: 'plugins.area.show',
                routeParameters: ['code' => $plugin->code],
                ability: $plugin->ability(),
                owner: $plugin->name,
                pluginLabel: $plugin->label,
            );
        }

        return $entries;
    }

    /**
     * The Tools hub's cards for the areas that aren't in the menu, those
     * this person may open, by the area's label with the plugin's name as
     * the badge.
     *
     * @return list<array{key: string, label: string, icon: string, description: string, href: string, external: bool, plugin: string}>
     */
    public function toolCards(User $user): array
    {
        $cards = [];

        foreach ($this->placed()['tools'] as $plugin) {
            if (! $user->can($plugin->ability())) {
                continue;
            }

            $cards[] = [
                'key' => "plugin.{$plugin->code}.area",
                'label' => $this->areaLabel($plugin),
                'icon' => $plugin->area->icon,
                'description' => $plugin->area->description !== ''
                    ? AreaWords::text($plugin->code, $plugin->area->description)
                    : $this->areaLabel($plugin),
                'href' => route('plugins.area.show', ['code' => $plugin->code], false),
                'external' => false,
                'plugin' => $plugin->label,
            ];
        }

        usort($cards, function (array $a, array $b): int {
            $byLabel = strnatcasecmp($a['label'], $b['label']);

            return $byLabel !== 0 ? $byLabel : strnatcasecmp($a['plugin'], $b['plugin']);
        });

        return $cards;
    }

    /**
     * The area's label as the person reads it.
     */
    private function areaLabel(AreaPlugin $plugin): string
    {
        return AreaWords::text($plugin->code, $plugin->area->label);
    }

    /**
     * Whether this plugin's area sits on the Tools hub, rather than in the
     * menu: Tools is then its parent in the top bar.
     */
    public function onTools(string $code): bool
    {
        return array_any($this->placed()['tools'], fn (AreaPlugin $plugin): bool => $plugin->code === $code);
    }

    /**
     * The areas split into those with a menu entry and those on the Tools hub.
     *
     * @return array{menu: list<AreaPlugin>, tools: list<AreaPlugin>}
     */
    private function placed(): array
    {
        $menu = [];
        $tools = [];

        foreach ($this->areas->all() as $plugin) {
            if ($plugin->area->menu !== 'tools' && count($menu) < self::MOST_ENTRIES) {
                $menu[] = $plugin;
            } else {
                $tools[] = $plugin;
            }
        }

        return ['menu' => $menu, 'tools' => $tools];
    }
}
