<?php

/**
 * The active theme's navigation slots for one page, each with its label and
 * the tree of the menu shown there, or null when none is: built once, on
 * first use, for the navigation component and the `$navigation` composer
 * both. It is scoped to a request, and start() empties it for each page, as
 * one application may serve several (B-227).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Menus;

use App\Domain\Menus\Models\Navigation;
use App\Domain\Themes\ActiveTheme;

final class SlotMenus
{
    private string $path = '';

    /** @var array<string, array{label: string, tree: list<array{label: string, url: string|null, current: bool, opensNewTab: bool, heading: bool, children: list<mixed>}>|null}>|null */
    private ?array $slots = null;

    /** @var array<string, int> How many times each slot's menu has been drawn on this page. */
    private array $drawn = [];

    public function __construct(
        private readonly ActiveTheme $active,
        private readonly ThemeSlotSettings $settings,
    ) {}

    /**
     * Empties it for a page about to be drawn, whose request path marks the current item.
     */
    public function start(string $path): void
    {
        $this->path = $path;
        $this->slots = null;
        $this->drawn = [];
    }

    /**
     * A name for one drawing of this slot's menu that no other drawing on
     * the page shares, which its headings' lists build their ids from:
     * `teme-menu-primary_1`, then `teme-menu-primary_2` where a theme draws
     * the slot twice, as the default theme's header does. A slot's slug
     * has no `_`, so no two names meet.
     */
    public function nextId(string $slot): string
    {
        $count = $this->drawn[$slot] = ($this->drawn[$slot] ?? 0) + 1;

        return "teme-menu-{$slot}_{$count}";
    }

    /**
     * Every slot the active theme declares, in its manifest's order, with
     * its label and its menu's tree, or null when it has none; empty with no
     * usable theme.
     *
     * @return array<string, array{label: string, tree: list<array{label: string, url: string|null, current: bool, opensNewTab: bool, heading: bool, children: list<mixed>}>|null}>
     */
    public function all(): array
    {
        if ($this->slots !== null) {
            return $this->slots;
        }

        $theme = $this->active->get();
        $labels = $theme?->manifest?->navigations() ?? [];

        if ($theme === null || $labels === []) {
            return $this->slots = [];
        }

        $bound = $this->settings->bound($theme);
        $menus = Navigation::query()->whereIn('id', array_values(array_filter($bound)))->get()->keyBy('id');
        $trees = [];
        $slots = [];

        foreach ($labels as $slot => $label) {
            $menu = $bound[$slot] === null ? null : $menus->get($bound[$slot]);

            // A menu shown in two slots is built once.
            $slots[$slot] = [
                'label' => $label,
                'tree' => $menu === null ? null : ($trees[$menu->id] ??= NavigationTree::for($menu, $this->path)),
            ];
        }

        return $this->slots = $slots;
    }

    /**
     * One slot, or null when the active theme doesn't declare it.
     *
     * @return array{label: string, tree: list<array{label: string, url: string|null, current: bool, opensNewTab: bool, heading: bool, children: list<mixed>}>|null}|null
     */
    public function slot(string $slot): ?array
    {
        return $this->all()[$slot] ?? null;
    }
}
