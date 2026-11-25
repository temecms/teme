<?php

/**
 * The admin's main menu as one person sees it: the registry's entries plus
 * one per listed content type and taxonomy and one per plugin area in the menu, a taxonomy under the first type
 * that uses it, less what they may not open, in section order, then as they
 * arranged it, each with its badge's count and, for a hub, its screens. A
 * preference can hide, reorder or pin, but never reveal anything (I-220).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Navigation\Queries;

use App\Domain\Navigation\BadgeProvider;
use App\Domain\Navigation\NavEntry;
use App\Domain\Navigation\NavigationRegistry;
use App\Domain\Navigation\NavSection;
use App\Domain\Plugins\Area\AreaEntries;
use App\Domain\Preferences\Queries\PreferencesFor;
use App\Domain\Settings\Modules;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TaxonomyRegistry;
use App\Models\User;

final readonly class AdminNavigation
{
    public function __construct(private NavigationRegistry $registry) {}

    /**
     * Every entry key that exists for anyone: the registry's entries and
     * their children, and one per listed content type and taxonomy. It checks
     * no ability; it is the set of keys a menu preference may name.
     *
     * @return list<string>
     */
    public function allKeys(): array
    {
        return array_map(fn (NavEntry $entry): string => $entry->key, [...$this->registry->all(), ...$this->typeEntries(), ...app(AreaEntries::class)->navEntries()]);
    }

    /**
     * The entries this user may open, each with a relative href, its
     * children, a hub's screens, and whether they hid it, sorted by section, then by their
     * own order (nav.order) with the rest after it, then by order and key.
     * Hidden entries are still returned: the sidebar leaves them out, and
     * the palette does not.
     *
     * @return list<array{key: string, label: string, icon: string, href: string, badge: int, soon?: true, section: string, hidden: bool, children: list<array{key: string, label: string, icon: string, href: string, badge: int, soon?: true}>, screens: list<array{key: string, label: string, icon: string, href: string}>}>
     */
    public function forUser(int|string|null $userId): array
    {
        $user = $userId === null ? null : User::query()->find($userId);

        if ($user === null) {
            return [];
        }

        // Later (I-217): an administrator sets a default menu per role; a person's own
        // preferences sit on top of it. Built when a site asks for it.
        $preferences = PreferencesFor::user($user);
        /** @var list<string> $hidden */
        $hidden = $preferences['nav.hidden'];
        /** @var list<string> $order */
        $order = $preferences['nav.order'];

        $visible = [];

        foreach ($this->sorted([...$this->registry->all(), ...$this->typeEntries(), ...app(AreaEntries::class)->navEntries()]) as $entry) {
            // An entry of a module that is switched off isn't there (I-077).
            if ($entry->module !== null && ! Modules::on($entry->module)) {
                continue;
            }

            if ($entry->ability !== null && ! $user->can($entry->ability)) {
                continue;
            }

            $screens = [];

            if ($entry->soon) {
                // Planned and not built: it has no address (I-3408).
                $href = '';
            } elseif ($entry->hub !== null) {
                $hub = $this->registry->hubFor($entry->hub);
                $href = $hub?->firstHrefFor($user);
                $screens = $hub?->pagesFor($user) ?? [];
            } else {
                $href = route((string) $entry->route, $entry->routeParameters, false);
            }

            if ($href !== null) {
                $visible[$entry->key] = ['entry' => $entry, 'href' => $href, 'screens' => $screens];
            }
        }

        // What waits for the person at each entry they may open, asked once (I-220).
        $counts = [];

        foreach ($visible as $key => ['entry' => $entry]) {
            /** @var BadgeProvider|null $provider */
            $provider = $entry->badge === null || $entry->soon ? null : app($entry->badge);
            $counts[$key] = $provider?->countFor($user) ?? 0;
        }

        $menu = [];

        foreach ($visible as $key => ['entry' => $entry, 'href' => $href, 'screens' => $screens]) {
            // A child sits under its parent; one whose parent this person
            // may not open stands at the top of its section instead (I-220).
            if ($entry->parent !== null && isset($visible[$entry->parent])) {
                continue;
            }

            $children = [];
            $badge = $counts[$key];

            foreach ($visible as $child) {
                if ($child['entry']->parent === $key) {
                    $children[] = [
                        'key' => $child['entry']->key,
                        'label' => $child['entry']->label,
                        'icon' => $child['entry']->icon,
                        'href' => $child['href'],
                        'badge' => $counts[$child['entry']->key],
                        ...($child['entry']->soon ? ['soon' => true] : []),
                    ];
                    // A parent counts what waits in its children too (I-220).
                    $badge += $counts[$child['entry']->key];
                }
            }

            $menu[] = [
                'key' => $entry->key,
                'label' => $entry->label,
                'icon' => $entry->icon,
                'href' => $href,
                'badge' => $badge,
                ...($entry->soon ? ['soon' => true] : []),
                ...($entry->pluginLabel !== null ? ['plugin' => $entry->pluginLabel] : []),
                'section' => $entry->section->value,
                'hidden' => in_array($entry->key, $hidden, true),
                'children' => $children,
                // A hub's screens, for the palette's Go to.
                'screens' => array_map(fn (array $screen): array => [
                    'key' => $screen['key'],
                    'label' => $screen['label'],
                    'icon' => $screen['icon'],
                    'href' => $screen['href'],
                    // A plugin's link to another site, which the palette opens in a new tab (I-3353).
                    ...(($screen['external'] ?? false) ? ['external' => true] : []),
                    ...(isset($screen['plugin']) ? ['plugin' => $screen['plugin']] : []),
                ], $screens),
            ];
        }

        // Applied after the visibility rule, so it only rearranges what the
        // person may open. Within a section: the keys nav.order names first,
        // in its order, the rest after them in their default order.
        $sections = array_flip(array_map(fn (NavSection $section): string => $section->value, NavSection::cases()));
        $ranks = array_flip($order);
        $positions = array_flip(array_column($menu, 'key'));

        usort($menu, fn (array $a, array $b): int => [$sections[$a['section']], $ranks[$a['key']] ?? PHP_INT_MAX, $positions[$a['key']]]
            <=> [$sections[$b['section']], $ranks[$b['key']] ?? PHP_INT_MAX, $positions[$b['key']]]);

        return $menu;
    }

    /**
     * The person's pinned entry keys (nav.pinned), in their order, less any
     * the menu they were given does not hold, such as an entry their role no
     * longer opens.
     *
     * @param  list<array{key: string, soon?: true, children: list<array{key: string, soon?: true}>}>  $menu  what forUser() returned for them
     *
     * @return list<string>
     */
    public function pinnedFor(?User $user, array $menu): array
    {
        if ($user === null) {
            return [];
        }

        $open = [];

        foreach ($menu as $entry) {
            // A planned entry opens nothing, so it is never pinned (I-3408).
            if (! ($entry['soon'] ?? false)) {
                $open[] = $entry['key'];
            }

            foreach ($entry['children'] as $child) {
                if (! ($child['soon'] ?? false)) {
                    $open[] = $child['key'];
                }
            }
        }

        /** @var list<string> $pinned */
        $pinned = PreferencesFor::user($user)['nav.pinned'];

        return array_values(array_filter($pinned, fn (string $key): bool => in_array($key, $open, true)));
    }

    /**
     * One entry per listed content type, then one per taxonomy, 100 apart in
     * registration order. A taxonomy is a child of the first listed type
     * that uses it; one no listed type uses stands on its own. The
     * registries are resolved on each call, so a type registered late is
     * always seen.
     *
     * @return list<NavEntry>
     */
    private function typeEntries(): array
    {
        $entries = [];
        $listed = array_values(array_filter(app(ContentTypeRegistry::class)->all(), fn (ContentType $type): bool => $type->listed));

        foreach ($listed as $type) {
            // A type stored in the admin is opened from Types, not as its own menu entry.
            if ($type->stored) {
                continue;
            }

            $entries[] = new NavEntry(
                $type->segment, $type->plural, $type->icon, NavSection::Content, 100 * (count($entries) + 1),
                route: 'content.index', routeParameters: ['type' => $type->segment], ability: $type->ability('create'),
            );

            // Pages tree sits under Pages; current on /admin/pages/tree.
            if ($type->hierarchical && $type->segment === 'pages') {
                $entries[] = new NavEntry(
                    'pages.tree', __('Pages tree'), 'list-tree', NavSection::Content, 100 * (count($entries) + 1),
                    route: 'content.tree', routeParameters: ['type' => 'pages'], ability: $type->ability('create'),
                    parent: 'pages',
                );
            }
        }

        foreach (app(TaxonomyRegistry::class)->all() as $taxonomy) {
            // One place, as two levels and unique keys allow (I-220).
            $owner = array_find($listed, fn (ContentType $type): bool => in_array($taxonomy->key, $type->taxonomies, true));

            $entries[] = new NavEntry(
                $taxonomy->segment, $taxonomy->plural, $taxonomy->icon, NavSection::Content, 100 * (count($entries) + 1),
                route: 'terms.index', routeParameters: ['taxonomy' => $taxonomy->segment], ability: $taxonomy->ability,
                parent: $owner?->segment,
            );
        }

        return $entries;
    }

    /**
     * @param  list<NavEntry>  $entries
     *
     * @return list<NavEntry>
     */
    private function sorted(array $entries): array
    {
        $sections = array_flip(array_map(fn (NavSection $section): string => $section->value, NavSection::cases()));

        usort($entries, fn (NavEntry $a, NavEntry $b): int => [$sections[$a->section->value], $a->order, $a->key] <=> [$sections[$b->section->value], $b->order, $b->key]);

        return $entries;
    }
}
