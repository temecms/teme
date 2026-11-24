<?php

/**
 * The Settings pages a person may open, for the Settings menu, the palette
 * and Find a setting: the settings groups they may manage and the pages
 * that aren't groups, in the menu's order. Groups and pages they may not
 * manage are never listed.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Settings\Queries;

use App\Domain\Plugins\Area\PluginAreas;
use App\Domain\Plugins\ManifestText;
use App\Domain\Settings\SettingsPage;
use App\Domain\Settings\SettingsPlacement;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Illuminate\Contracts\Container\Container;

final class SettingsNavigation
{
    /** Lets a person see every settings group (SettingsController::VIEW). */
    private const string VIEW = 'settings.view';

    /** The heading over core's Settings pages. */
    public const string SITE = 'Site';

    /** The heading over plugins' groups, which never sit among core's. */
    public const string PLUGINS = 'From plugins';

    /**
     * Core's Settings pages in the order the menu shows them. A page this
     * list doesn't name follows them, in the order its part registered it
     * (docs/architecture/admin-design.md, the parity sweep's item 8).
     */
    private const array ORDER = ['general', 'writing', 'reading', 'discussion', 'seo', 'addresses', 'media', 'people', 'site-code', 'ai'];

    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly Container $container,
        private readonly PluginAreas $areas,
    ) {}

    /**
     * The Settings pages this user may open, each with its relative address
     * and its heading: core's under Site, a plugin's group (its key starts
     * `plugin.`) under From plugins. A plugin with an area sets ownPage and
     * links to that area's Settings tab. Sorted by heading, then by ORDER,
     * then as they registered.
     *
     * @return list<array{key: string, label: string, href: string, heading: string, plugin: bool, ownPage: bool}>
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

            // Someone who may see settings sees every group, read-only where they may not manage it.
            if ($user->can($group->ability) || ($user->can(self::VIEW) && $group->screen === null)) {
                // A group drawn under another page's name is listed as that
                // page, as the content group is as Writing.
                $page = SettingsPlacement::pageShowing($group->key);
                $label = SettingsPlacement::RENAMED[$page]['label'] ?? $group->label;
                // A plugin's group in the person's language, from its own lang folder (docs/plans/plugin-sockets-2.md).
                $found[] = ['key' => $page, 'label' => str_starts_with($group->key, 'plugin.') ? ManifestText::for(substr($group->key, strlen('plugin.')))->text($label, app()->getLocale()) : $label];
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
            $code = $plugin ? substr($item['key'], strlen('plugin.')) : '';
            $ownPage = $plugin && $this->areas->hasSettingsTab($code);
            $place = array_search($item['key'], self::ORDER, true);

            $ranked[] = [
                [$plugin ? 1 : 0, $place === false ? PHP_INT_MAX : $place, $position],
                [
                    'key' => $item['key'],
                    'label' => $item['label'],
                    'href' => $ownPage
                        ? route('plugins.area.settings', ['code' => $code], false)
                        : route('settings.show', ['group' => $item['key']], false),
                    'heading' => $plugin ? self::PLUGINS : self::SITE,
                    'plugin' => $plugin,
                    'ownPage' => $ownPage,
                ],
            ];
        }

        usort($ranked, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_map(fn (array $entry): array => $entry[1], $ranked);
    }
}
