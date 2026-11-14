<?php

declare(strict_types=1);

namespace App\Domain\Settings\Queries;

use App\Domain\Settings\SettingsPage;
use App\Domain\Settings\SettingsPlacement;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Illuminate\Contracts\Container\Container;

/**
 * The Settings pages a person may open, for the Settings menu, the palette
 * and Find a setting: the settings groups they may manage and the pages
 * that aren't groups, in the menu's order. Groups and pages they may not
 * manage are never listed.
 */
final class SettingsNavigation
{
    /** The heading over core's Settings pages. */
    public const string SITE = 'Site';

    /** The heading over plugins' groups, which never sit among core's. */
    public const string PLUGINS = 'Plugin settings';

    /**
     * Core's Settings pages in the order the menu shows them. A page this
     * list doesn't name follows them, in the order its part registered it
     * (docs/architecture/admin-design.md, the parity sweep's item 8).
     */
    private const array ORDER = ['general', 'writing', 'reading', 'discussion', 'seo', 'addresses', 'media', 'people', 'site-code', 'ai'];

    public function __construct(private readonly SettingsRegistry $registry, private readonly Container $container) {}

    /**
     * The Settings pages this user may open, each with its relative address
     * and its heading: core's under Site, a plugin's group (its key starts
     * `plugin.`) under Plugin settings. Sorted by heading, then by ORDER,
     * then as they registered.
     *
     * @return list<array{key: string, label: string, href: string, heading: string}>
     */
    public function forUser(int|string|null $userId): array
    {
        $user = $userId === null ? null : User::query()->find($userId);

        if ($user === null) {
            return [];
        }

        $found = [];

        foreach ($this->registry->all() as $group) {
            // A group is its own page when it has no screen, or when its
            // screen is the Settings route named for it, as Addresses' is.
            // The design's groups, changed on Site › Themes, aren't.
            if ($group->screen !== null && $group->screen !== 'settings.'.$group->key) {
                continue;
            }

            if ($user->can($group->ability)) {
                // A group drawn under another page's name is listed as that
                // page, as the content group is as Writing.
                $page = SettingsPlacement::pageShowing($group->key);
                $found[] = ['key' => $page, 'label' => SettingsPlacement::RENAMED[$page]['label'] ?? $group->label];
            }
        }

        // Then the Settings pages that aren't groups, such as Site code.
        foreach ($this->container->tagged(SettingsPage::TAG) as $page) {
            if ($page instanceof SettingsPage && $user->can($page->ability())) {
                $found[] = ['key' => $page->key(), 'label' => $page->label()];
            }
        }

        $ranked = [];

        foreach ($found as $position => $item) {
            $plugin = str_starts_with($item['key'], 'plugin.');
            $place = array_search($item['key'], self::ORDER, true);

            $ranked[] = [
                [$plugin ? 1 : 0, $place === false ? PHP_INT_MAX : $place, $position],
                [
                    'key' => $item['key'],
                    'label' => $item['label'],
                    'href' => route('settings.show', ['group' => $item['key']], false),
                    'heading' => $plugin ? self::PLUGINS : self::SITE,
                ],
            ];
        }

        usort($ranked, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_map(fn (array $entry): array => $entry[1], $ranked);
    }
}
